<?php

declare(strict_types=1);

use Sifrious\Tarrou\Dns\RecordSpec;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneObservedState;
use Sifrious\Tarrou\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function record(
    string $type,
    string $name,
    string $content,
    int $ttl = 3600,
    ?int $priority = null,
    ?bool $proxied = null,
): RecordSpec {
    return new RecordSpec(RecordType::parse($type), $name, $content, $ttl, $priority, $proxied);
}


/**
 * An observation with a time on it. Plans built from an observation with no
 * time are never applicable, which is deliberate — so tests that are about
 * something else say when they looked.
 *
 * @param  list<RecordSpec>  $records
 * @param  list<string>  $nameservers
 */
function observed(
    string $domain,
    array $records = [],
    array $nameservers = [],
    ?TlsMode $tlsMode = null,
    ?DateTimeImmutable $observedAt = null,
    bool $complete = true,
): ZoneObservedState {
    return new ZoneObservedState(
        domain: $domain,
        records: $records,
        nameservers: $nameservers,
        tlsMode: $tlsMode,
        observedAt: $observedAt ?? new DateTimeImmutable,
        complete: $complete,
    );
}
