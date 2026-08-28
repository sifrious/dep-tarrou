<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sifrious\Tarrou\TarrouServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TarrouServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tarrou.standard_record_set.origin_address', '203.0.113.10');
    }
}
