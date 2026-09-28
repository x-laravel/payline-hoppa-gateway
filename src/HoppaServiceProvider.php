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

        $this->app->make('payline.bin_lookup')->extend('hoppa', function ($app, array $config) {
            $apiUrl = $config['api_url'] ?? $app['config']['payline.gateways.hoppa.api_url'];
            return new HoppaBinLookupProvider($apiUrl);
        });
    }
}
