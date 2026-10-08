<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Illuminate\Redis\Connections\Connection;
use Redis;

/**
 * SCAN helper shared across repository and CLI code. Works around the fact
 * that predis and phpredis both auto-prefix commands that take keys, but the
 * MATCH parameter of SCAN is a raw Redis pattern and is not prefixed, so the
 * pattern and the returned keys would be out of sync without adjustment.
 */
trait ScansRedisByPrefix
{
    /**
     * @param callable(array<string>): void $onBatch Receives each batch of keys (already stripped of the connection prefix).
     */
    protected function scanByPattern(Connection $connection, string $pattern, callable $onBatch): void
    {
        $connectionPrefix = $this->connectionPrefix($connection);

        // Start from a null cursor: phpredis treats a 0 / "0" iterator as an
        // already exhausted scan and returns false without asking the server.
        $cursor = null;

        do {
            // Option keys must be lowercase: PhpRedisConnection::scan() only
            // reads 'match' / 'count' and silently scans '*' otherwise, while
            // predis accepts either case.
            /** @var array{0: int|string, 1: array<string>}|false $result */
            $result = $connection->scan($cursor, ['match' => $connectionPrefix . $pattern, 'count' => 100]); // @phpstan-ignore argument.type

            // phpredis (through Laravel) answers false when the scan finishes
            // without a final batch of keys.
            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;
            $keys = $this->stripConnectionPrefix($keys, $connectionPrefix);

            if ($keys !== []) {
                $onBatch($keys);
            }
        } while ((string) $cursor !== '0');
    }

    protected function connectionPrefix(Connection $connection): string
    {
        $client = $connection->client();

        if (interface_exists('Predis\\ClientInterface') && $client instanceof \Predis\ClientInterface) {
            /** @var object|null $prefix */
            $prefix = $client->getOptions()->__get('prefix');

            if (is_object($prefix) && method_exists($prefix, 'getPrefix')) {
                $value = $prefix->getPrefix();

                return is_string($value) ? $value : '';
            }

            return '';
        }

        if (class_exists(Redis::class) && $client instanceof Redis) {
            /** @var mixed $prefix */
            $prefix = $client->getOption(Redis::OPT_PREFIX);

            return is_string($prefix) ? $prefix : '';
        }

        return '';
    }

    /**
     * @param array<string> $keys
     *
     * @return array<string>
     */
    protected function stripConnectionPrefix(array $keys, string $connectionPrefix): array
    {
        if ($connectionPrefix === '') {
            return $keys;
        }

        $length = strlen($connectionPrefix);

        return array_map(
            static fn (string $key): string => str_starts_with($key, $connectionPrefix) ? substr($key, $length) : $key,
            $keys,
        );
    }
}
