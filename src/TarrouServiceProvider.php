<?php

declare(strict_types=1);

namespace Sifrious\Tarrou;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Sifrious\Tarrou\Apply\Applier;
use Sifrious\Tarrou\Plan\Planner;
use Sifrious\Tarrou\Policy\DnsPolicy;
use Sifrious\Tarrou\Policy\StandardRecordSet;

class TarrouServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tarrou.php', 'tarrou');

        $this->app->singleton(Planner::class);
        $this->app->singleton(Applier::class);
        $this->app->singleton(DnsPolicy::class);

        $this->app->bind(StandardRecordSet::class, function (Application $app): StandardRecordSet {
            $settings = (array) $app->make(Config::class)->get('tarrou.standard_record_set', []);

            return new StandardRecordSet(
                originAddress: (string) ($settings['origin_address'] ?? ''),
                ttl: (int) ($settings['ttl'] ?? 3600),
                proxied: (bool) ($settings['proxied'] ?? true),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tarrou.php' => $this->app->configPath('tarrou.php'),
            ], 'tarrou-config');
        }
    }
}
