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
        if ($this->redisAvailable()) {
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

        $app['config']->set('database.redis.client', 'predis');
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
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} $result */
            $result = $connection->command('scan', [$cursor, 'match', self::TEST_PREFIX . '*', 'count', 500]);
            $cursor = $result[0];
            $keys = $result[1];

            if ($keys !== []) {
                $connection->command('del', $keys);
            }
        } while ($cursor !== '0');
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
