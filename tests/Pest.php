<?php

declare(strict_types=1);

use Scabarcas\LaravelPermissionsRedis\Tests\Redis\TestCase as RedisTestCase;
use Scabarcas\LaravelPermissionsRedis\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Integration', 'Performance');
uses(RedisTestCase::class)->in('Redis');
