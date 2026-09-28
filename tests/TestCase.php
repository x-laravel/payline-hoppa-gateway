<?php

namespace XLaravel\Payline\Gateways\Hoppa\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Payline\PaylineServiceProvider;
use XLaravel\Payline\Gateways\Hoppa\HoppaServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaylineServiceProvider::class,
            HoppaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payline.default', 'hoppa');
        $app['config']->set('payline.gateways.hoppa', [
            'test_mode' => true,
            'merchant_id' => 'TEST_MERCHANT',
            'merchant_key' => 'TEST_KEY',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/x-laravel/payline/database/migrations');
    }
}
