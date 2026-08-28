<?php

declare(strict_types=1);

use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneDesiredState;
use Sifrious\Tarrou\Policy\AuthoritativeProvider;
use Sifrious\Tarrou\Policy\DnsPolicy;
use Sifrious\Tarrou\Policy\DomainStanding;
use Sifrious\Tarrou\Policy\StandardRecordSet;

it('builds the standard record set as an apex A and a www CNAME', function (): void {
    $records = (new StandardRecordSet('203.0.113.10'))->records('Example.Test');

    expect($records)->toHaveCount(2)
        ->and($records[0]->type)->toBe(RecordType::A)
        ->and($records[0]->normalizedName())->toBe('example.test')
        ->and($records[1]->type)->toBe(RecordType::CNAME)
        ->and($records[1]->normalizedName())->toBe('www.example.test')
        ->and($records[1]->normalizedContent())->toBe('example.test');
});

it('forces Full (strict) when the standard record set proxies', function (): void {
    expect((new StandardRecordSet('203.0.113.10'))->desiredState('example.test')->tlsMode)
        ->toBe(TlsMode::FullStrict);
});

it('accepts a standard desired state', function (): void {
    expect((new DnsPolicy)->permits((new StandardRecordSet('203.0.113.10'))->desiredState('example.test')))
        ->toBeTrue();
});

it('rejects Flexible TLS in front of a proxied origin', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: (new StandardRecordSet('203.0.113.10'))->records('example.test'),
        tlsMode: TlsMode::Flexible,
    );

    $violations = (new DnsPolicy)->violations($desired);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(DnsPolicy::RULE_PROXIED_REQUIRES_FULL_STRICT)
        ->and($violations[0]->detail)->toContain('full_strict');
});

it('rejects proxied records with no TLS mode at all', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: (new StandardRecordSet('203.0.113.10'))->records('example.test'),
    );

    expect((new DnsPolicy)->violations($desired)[0]->rule)
        ->toBe(DnsPolicy::RULE_PROXIED_REQUIRES_FULL_STRICT);
});

it('rejects a CNAME at the apex', function (): void {
    $desired = new ZoneDesiredState('example.test', [
        record('CNAME', 'example.test', 'origin.test'),
        record('CNAME', 'www.example.test', 'example.test'),
    ]);

    $rules = array_map(fn ($violation) => $violation->rule, (new DnsPolicy)->violations($desired));

    expect($rules)->toContain(DnsPolicy::RULE_NO_CNAME_AT_APEX);
});

it('requires both an apex and a www record', function (): void {
    $rules = array_map(
        fn ($violation) => $violation->rule,
        (new DnsPolicy)->violations(new ZoneDesiredState('example.test')),
    );

    expect($rules)->toContain(DnsPolicy::RULE_APEX_REQUIRED)
        ->and($rules)->toContain(DnsPolicy::RULE_WWW_REQUIRED);
});

it('does not migrate a domain that serves nothing', function (): void {
    $parked = new DomainStanding('parked.test');
    $registered = new DomainStanding('planned.test', attachedToSite: true);
    $live = new DomainStanding('live.test', attachedToSite: true, siteIsLiveOrCommitted: true);
    $expired = new DomainStanding('gone.test', attachedToSite: true, siteIsLiveOrCommitted: true, expired: true);

    expect($parked->eligibleForMigration())->toBeFalse()
        ->and($registered->eligibleForMigration())->toBeFalse()
        ->and($live->eligibleForMigration())->toBeTrue()
        ->and($expired->eligibleForMigration())->toBeFalse();
});

it('keeps authority at the registrar unless the domain serves public traffic', function (): void {
    expect(AuthoritativeProvider::forStanding(new DomainStanding('parked.test')))
        ->toBe(AuthoritativeProvider::Registrar)
        ->and(AuthoritativeProvider::forStanding(
            new DomainStanding('live.test', attachedToSite: true, siteIsLiveOrCommitted: true)
        ))->toBe(AuthoritativeProvider::Edge);
});

it('refuses a desired state that declares two CNAMEs at one name', function (): void {
    new ZoneDesiredState('example.test', [
        record('CNAME', 'www.example.test', 'a.test'),
        record('CNAME', 'www.example.test', 'b.test'),
    ]);
})->throws(InvalidArgumentException::class);
