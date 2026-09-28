<?php

namespace XLaravel\Payline\Gateways\Hoppa;

use Illuminate\Support\ServiceProvider;

class HoppaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('payline')->extend('hoppa', function ($app, array $config) {
            return new HoppaGateway($config);
        });
    }
}
