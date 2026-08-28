<?php

declare(strict_types=1);

use Sifrious\Tarrou\Registrar\Association;
use Sifrious\Tarrou\Registrar\DomainRegistration;
use Sifrious\Tarrou\Registrar\FleetInventory;
use Sifrious\Tarrou\Registrar\RenewalRisk;

const NOW = '2026-08-28T00:00:00+00:00';

function asOf(): DateTimeImmutable
{
    return new DateTimeImmutable(NOW);
}

function registration(string $domain, array $overrides = []): DomainRegistration
{
    return new DomainRegistration(
        domain: $domain,
        observedAt: new DateTimeImmutable($overrides['observed_at'] ?? NOW),
        registrar: 'namecheap',
        expiresAt: array_key_exists('expires_at', $overrides)
            ? ($overrides['expires_at'] === null ? null : new DateTimeImmutable($overrides['expires_at']))
            : new DateTimeImmutable('2027-08-28T00:00:00+00:00'),
        autoRenew: $overrides['auto_renew'] ?? true,
        privacy: $overrides['privacy'] ?? 'enabled',
        nameservers: $overrides['nameservers'] ?? [],
        usesRegistrarDns: $overrides['uses_registrar_dns'] ?? true,
    );
}

it('flags a lapsed registration above everything else', function (): void {
    $rows = (new FleetInventory)->rows(
        [
            registration('safe.test'),
            registration('beam.ong', ['expires_at' => '2026-08-01T00:00:00+00:00', 'auto_renew' => false]),
        ],
        ['beam.ong' => Association::ApprovedForRelease],
        asOf(),
    );

    expect($rows[0]->domain())->toBe('beam.ong')
        ->and($rows[0]->risk)->toBe(RenewalRisk::Lapsed)
        ->and($rows[0]->daysUntilExpiry)->toBe(-27)
        ->and($rows[0]->needsAction())->toBeTrue();
});

it('calls a domain unprotected when auto-renew is off inside the horizon', function (): void {
    $rows = (new FleetInventory)->rows(
        [registration('heynamatic.com', ['expires_at' => '2026-10-01T00:00:00+00:00', 'auto_renew' => false])],
        ['heynamatic.com' => Association::Live],
        asOf(),
    );

    expect($rows[0]->risk)->toBe(RenewalRisk::Unprotected)
        ->and($rows[0]->findings)->toContain('Auto-renew is off on a domain attached to a live or committed site.');
});

it('still flags auto-renew off beyond the horizon when the domain must not lapse', function (): void {
    $inventory = new FleetInventory;

    $live = $inventory->rows(
        [registration('live.test', ['expires_at' => '2027-06-01T00:00:00+00:00', 'auto_renew' => false])],
        ['live.test' => Association::Committed],
        asOf(),
    );

    $parked = $inventory->rows(
        [registration('parked.test', ['expires_at' => '2027-06-01T00:00:00+00:00', 'auto_renew' => false])],
        ['parked.test' => Association::Parked],
        asOf(),
    );

    expect($live[0]->risk)->toBe(RenewalRisk::Unprotected_Later)
        ->and($parked[0]->risk)->toBe(RenewalRisk::None);
});

it('expects a domain inside the horizon with auto-renew on to renew itself', function (): void {
    $rows = (new FleetInventory)->rows(
        [registration('fine.test', ['expires_at' => '2026-09-15T00:00:00+00:00', 'auto_renew' => true])],
        ['fine.test' => Association::Live],
        asOf(),
    );

    expect($rows[0]->risk)->toBe(RenewalRisk::Renewing)
        ->and($rows[0]->risk->needsAction())->toBeFalse();
});

it('says unknown rather than fine when no expiry was observed', function (): void {
    $rows = (new FleetInventory)->rows(
        [registration('mystery.test', ['expires_at' => null])],
        ['mystery.test' => Association::Live],
        asOf(),
    );

    expect($rows[0]->risk)->toBe(RenewalRisk::Unknown)
        ->and($rows[0]->findings)->toContain('No expiry date was observed.');
});

it('refuses to present a stale observation as current', function (): void {
    $rows = (new FleetInventory)->rows(
        [registration('stale.test', ['observed_at' => '2026-04-19T00:00:00+00:00'])],
        ['stale.test' => Association::Live],
        asOf(),
    );

    expect($rows[0]->observationIsStale)->toBeTrue()
        ->and($rows[0]->needsAction())->toBeTrue()
        ->and($rows[0]->findings)->toContain('Observed 131 days ago; re-observe before acting on it.');
});

it('lists every domain exactly once and keeps the newest observation', function (): void {
    $rows = (new FleetInventory)->rows(
        [
            registration('twice.test', ['observed_at' => '2026-04-19T00:00:00+00:00', 'auto_renew' => false]),
            registration('twice.test', ['observed_at' => NOW, 'auto_renew' => true]),
        ],
        [],
        asOf(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->registration->autoRenew)->toBeTrue()
        ->and($rows[0]->observationIsStale)->toBeFalse();
});

it('treats an undecided association as a finding rather than as a default', function (): void {
    $rows = (new FleetInventory)->rows([registration('orphan.test')], [], asOf());

    expect($rows[0]->association)->toBe(Association::Unassigned)
        ->and($rows[0]->findings)->toContain('No project or site association has been decided.')
        ->and($rows[0]->needsAction())->toBeTrue();
});

it('does not treat unavailable privacy as an oversight', function (): void {
    $rows = (new FleetInventory)->rows(
        [
            registration('mary.is', ['privacy' => 'unavailable']),
            registration('exposed.test', ['privacy' => 'disabled']),
        ],
        ['mary.is' => Association::Live, 'exposed.test' => Association::Live],
        asOf(),
    );

    $byDomain = collect($rows)->keyBy(fn ($row) => $row->domain());

    expect($byDomain['mary.is']->findings)->not->toContain('WHOIS privacy is available and switched off.')
        ->and($byDomain['exposed.test']->findings)->toContain('WHOIS privacy is available and switched off.');
});

it('summarises the fleet by risk, staleness and assignment', function (): void {
    $inventory = new FleetInventory;

    $rows = $inventory->rows(
        [
            registration('beam.ong', ['expires_at' => '2026-08-01T00:00:00+00:00', 'auto_renew' => false]),
            registration('heynamatic.com', ['expires_at' => '2026-10-01T00:00:00+00:00', 'auto_renew' => false]),
            registration('stale.test', ['observed_at' => '2026-04-19T00:00:00+00:00']),
            registration('fine.test'),
        ],
        ['heynamatic.com' => Association::Live, 'fine.test' => Association::Live, 'stale.test' => Association::Live],
        asOf(),
    );

    $summary = $inventory->summary($rows);

    expect($summary['total'])->toBe(4)
        ->and($summary['lapsed'])->toBe(1)
        ->and($summary['unprotected'])->toBe(1)
        ->and($summary['stale'])->toBe(1)
        ->and($summary['unassigned'])->toBe(1)
        ->and($summary['needs_action'])->toBe(3);
});

it('builds a registration from a connector normalized payload without inventing fields', function (): void {
    $registration = DomainRegistration::fromNormalized([
        'domain' => 'HeyNamatic.COM',
        'expires_at' => '2026-05-14T00:00:00+00:00',
        'auto_renew' => false,
        'privacy' => 'enabled',
        'uses_registrar_dns' => true,
        'provider_id' => '101',
    ], asOf(), 'namecheap');

    expect($registration->domain)->toBe('heynamatic.com')
        ->and($registration->autoRenew)->toBeFalse()
        ->and($registration->sourceReference)->toBe('101')
        ->and($registration->nameservers)->toBe([]);

    $sparse = DomainRegistration::fromNormalized(['domain' => 'sparse.test'], asOf());

    expect($sparse->autoRenew)->toBeNull()
        ->and($sparse->expiresAt)->toBeNull()
        ->and($sparse->privacy)->toBeNull();
});
