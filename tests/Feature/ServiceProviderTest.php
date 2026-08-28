<?php

declare(strict_types=1);

use Sifrious\Tarrou\Apply\Applier;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Plan\Planner;
use Sifrious\Tarrou\Policy\DnsPolicy;
use Sifrious\Tarrou\Policy\StandardRecordSet;

it('resolves the planning surface from the container', function (): void {
    expect(app(Planner::class))->toBeInstanceOf(Planner::class)
        ->and(app(Applier::class))->toBeInstanceOf(Applier::class)
        ->and(app(DnsPolicy::class))->toBeInstanceOf(DnsPolicy::class);
});

it('builds the standard record set from configuration', function (): void {
    expect(app(StandardRecordSet::class)->originAddress)->toBe('203.0.113.10');
});

it('declares Full (strict) as the configured TLS mode', function (): void {
    expect(config('tarrou.tls_mode'))->toBe(TlsMode::FullStrict->value);
});
