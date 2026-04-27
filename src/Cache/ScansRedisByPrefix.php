<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Cache;

use Illuminate\Redis\Connections\Connection;
use Redis;

/**
 * SCAN helper shared across repository and CLI code. Works around the fact
 * that predis and phpredis both auto-prefix commands that take keys, but the
 * MATCH parameter of SCAN is a raw Redis pattern and is NOT prefixed — so the
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
        $cursor = '0';

        do {
            /** @var array{0: string|int, 1: array<string>} $result */
            $result = $connection->scan($cursor, ['MATCH' => $connectionPrefix . $pattern, 'COUNT' => 100]); // @phpstan-ignore argument.type
            $cursor = (string) $result[0];
            $keys = $this->stripConnectionPrefix($result[1], $connectionPrefix);

            if ($keys !== []) {
                $onBatch($keys);
            }
        } while ($cursor !== '0');
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
