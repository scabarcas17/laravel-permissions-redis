<?php

declare(strict_types=1);

namespace Scabarcas\LaravelPermissionsRedis\Tests\Redis;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Scabarcas\LaravelPermissionsRedis\Cache\RedisPermissionRepository;
use Scabarcas\LaravelPermissionsRedis\Contracts\PermissionRepositoryInterface;
use Scabarcas\LaravelPermissionsRedis\PermissionsRedisServiceProvider;
use Scabarcas\LaravelPermissionsRedis\Tests\Fixtures\User;

abstract class TestCase extends OrchestraTestCase
{
    protected const TEST_PREFIX = 'lpr_contract_test:';

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->clientAvailable()) {
            $this->markTestSkipped(
                'PERMISSIONS_REDIS_TEST_CLIENT=phpredis requires the redis extension (phpredis).'
            );
        }

        if (!$this->redisAvailable()) {
            $this->markTestSkipped(
                'Redis server not available at ' . $this->redisHost() . ':' . $this->redisPort() .
                '. Start Redis or set PERMISSIONS_REDIS_TEST_SKIP=1 to silence.'
            );
        }

        $this->app->singleton(PermissionRepositoryInterface::class, RedisPermissionRepository::class);

        $this->flushTestKeys();
    }

    protected function tearDown(): void
    {
        if ($this->clientAvailable() && $this->redisAvailable()) {
            $this->flushTestKeys();
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [PermissionsRedisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('database.redis.client', $this->redisClient());
        $app['config']->set('database.redis.default', [
            'host'     => $this->redisHost(),
            'port'     => $this->redisPort(),
            'database' => $this->redisDatabase(),
        ]);

        $app['config']->set('permissions-redis.redis_connection', 'default');
        $app['config']->set('permissions-redis.prefix', self::TEST_PREFIX);
        $app['config']->set('permissions-redis.user_model', User::class);
        $app['config']->set('permissions-redis.register_gate', false);
        $app['config']->set('permissions-redis.register_middleware', false);
        $app['config']->set('permissions-redis.warm_on_login', false);
    }

    private function flushTestKeys(): void
    {
        /** @var \Illuminate\Redis\Connections\Connection $connection */
        $connection = Redis::connection('default');

        // SCAN MATCH is a raw server-side pattern: neither client prepends the
        // configured key prefix to it, and the returned keys carry that prefix,
        // so it is added to the pattern here and stripped before DEL re-adds it.
        $clientPrefix = $this->clientPrefix();

        // The connection-level scan() is the client-agnostic entry point (the
        // raw SCAN signatures of predis and phpredis differ). The cursor must
        // start as null: phpredis treats 0 / "0" as an already finished scan.
        $cursor = null;

        do {
            /** @var array{0: int|string, 1: array<string>}|false $result */
            $result = $connection->scan($cursor, ['match' => $clientPrefix . self::TEST_PREFIX . '*', 'count' => 500]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            if ($clientPrefix !== '') {
                $length = strlen($clientPrefix);
                $keys = array_map(
                    static fn (string $key): string => str_starts_with($key, $clientPrefix) ? substr($key, $length) : $key,
                    $keys,
                );
            }

            if ($keys !== []) {
                $connection->command('del', $keys);
            }
        } while ((string) $cursor !== '0');
    }

    private function clientPrefix(): string
    {
        /** @var string|null $prefix */
        $prefix = config('database.redis.options.prefix');

        return $prefix ?? '';
    }

    /**
     * Client driver under test: "predis" (default) or "phpredis". CI runs the
     * suite with both, since their argument and reply shapes differ.
     */
    private function redisClient(): string
    {
        return (string) (getenv('PERMISSIONS_REDIS_TEST_CLIENT') ?: 'predis');
    }

    private function clientAvailable(): bool
    {
        return $this->redisClient() !== 'phpredis' || extension_loaded('redis');
    }

    private function redisAvailable(): bool
    {
        if (getenv('PERMISSIONS_REDIS_TEST_SKIP') === '1') {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->redisHost(), $this->redisPort(), $errno, $errstr, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function redisHost(): string
    {
        return (string) (getenv('PERMISSIONS_REDIS_TEST_HOST') ?: '127.0.0.1');
    }

    private function redisPort(): int
    {
        $port = getenv('PERMISSIONS_REDIS_TEST_PORT');

        return $port !== false ? (int) $port : 6379;
    }

    private function redisDatabase(): int
    {
        $db = getenv('PERMISSIONS_REDIS_TEST_DB');

        return $db !== false ? (int) $db : 15;
    }
}
