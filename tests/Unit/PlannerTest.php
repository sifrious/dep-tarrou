<?php

declare(strict_types=1);

use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneDesiredState;
use Sifrious\Tarrou\Dns\ZoneObservedState;
use Sifrious\Tarrou\Plan\OperationKind;
use Sifrious\Tarrou\Plan\Planner;
use Sifrious\Tarrou\Plan\Risk;

it('proposes nothing when the zone already matches', function (): void {
    $records = [record('A', 'example.test', '203.0.113.10'), record('CNAME', 'www.example.test', 'example.test')];

    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', $records),
        new ZoneObservedState('example.test', $records),
    );

    expect($plan->isConverged())->toBeTrue()
        ->and($plan->operations)->toBeEmpty();
});

it('creates a record the zone does not have', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]),
        ZoneObservedState::empty('example.test'),
    );

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->kind)->toBe(OperationKind::CreateRecord)
        ->and($plan->operations[0]->risk)->toBe(Risk::Additive)
        ->and($plan->operations[0]->before)->toBeNull();
});

it('updates a singular record whose content changed rather than replacing it', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('CNAME', 'www.example.test', 'example.test')]),
        new ZoneObservedState('example.test', [record('CNAME', 'www.example.test', 'old.example.test')]),
    );

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->kind)->toBe(OperationKind::UpdateRecord)
        ->and($plan->operations[0]->before['content'])->toBe('old.example.test')
        ->and($plan->operations[0]->reason)->toContain('content');
});

it('treats a changed A record content as a create and a delete because both may coexist', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.11')]),
        new ZoneObservedState('example.test', [record('A', 'example.test', '203.0.113.10')]),
    );

    $kinds = array_map(fn ($operation) => $operation->kind, $plan->operations);

    expect($kinds)->toBe([OperationKind::CreateRecord, OperationKind::DeleteRecord]);
});

it('never proposes deleting a record type the desired state does not manage', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: [record('A', 'example.test', '203.0.113.10')],
        managedTypes: [RecordType::A],
    );

    $observed = new ZoneObservedState('example.test', [
        record('A', 'example.test', '203.0.113.10'),
        record('MX', 'example.test', 'mail.example.test', priority: 10),
        record('TXT', 'example.test', 'v=spf1 -all'),
    ]);

    expect((new Planner)->plan($desired, $observed)->isConverged())->toBeTrue();
});

it('deletes an unmanaged record when its type is managed', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: [record('A', 'example.test', '203.0.113.10')],
        managedTypes: [RecordType::A],
    );

    $observed = new ZoneObservedState('example.test', [
        record('A', 'example.test', '203.0.113.10'),
        record('A', 'stale.example.test', '198.51.100.7'),
    ]);

    $plan = (new Planner)->plan($desired, $observed);

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->kind)->toBe(OperationKind::DeleteRecord)
        ->and($plan->operations[0]->risk)->toBe(Risk::Destructive)
        ->and($plan->requiresExplicitConfirmation())->toBeTrue();
});

it('proposes a delegation when the nameservers differ, regardless of order', function (): void {
    $unordered = new ZoneObservedState('example.test', [], ['b.ns.test', 'a.ns.test']);

    $same = (new Planner)->plan(
        new ZoneDesiredState('example.test', [], ['a.ns.test', 'b.ns.test']),
        $unordered,
    );

    $different = (new Planner)->plan(
        new ZoneDesiredState('example.test', [], ['c.ns.test', 'd.ns.test']),
        $unordered,
    );

    expect($same->isConverged())->toBeTrue()
        ->and($different->operations)->toHaveCount(1)
        ->and($different->operations[0]->kind)->toBe(OperationKind::SetNameservers)
        ->and($different->operations[0]->risk)->toBe(Risk::Delegating);
});

it('proposes a TLS change only when the desired state declares one', function (): void {
    $silent = (new Planner)->plan(
        new ZoneDesiredState('example.test'),
        new ZoneObservedState('example.test', tlsMode: TlsMode::Flexible),
    );

    $declared = (new Planner)->plan(
        new ZoneDesiredState('example.test', tlsMode: TlsMode::FullStrict),
        new ZoneObservedState('example.test', tlsMode: TlsMode::Flexible),
    );

    expect($silent->isConverged())->toBeTrue()
        ->and($declared->operations[0]->kind)->toBe(OperationKind::SetTlsMode)
        ->and($declared->operations[0]->after['tls_mode'])->toBe('full_strict');
});

it('orders creations before deletions and delegation last', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: [record('A', 'new.example.test', '203.0.113.11')],
        nameservers: ['a.ns.test'],
        tlsMode: TlsMode::FullStrict,
        managedTypes: [RecordType::A],
    );

    $observed = new ZoneObservedState('example.test', [record('A', 'old.example.test', '198.51.100.7')], ['z.ns.test']);

    $kinds = array_map(fn ($operation) => $operation->kind->value, (new Planner)->plan($desired, $observed)->operations);

    expect($kinds)->toBe([
        OperationKind::CreateRecord->value,
        OperationKind::SetTlsMode->value,
        OperationKind::DeleteRecord->value,
        OperationKind::SetNameservers->value,
    ]);
});

it('hashes the same inputs to the same plan regardless of declaration order', function (): void {
    $observed = ZoneObservedState::empty('example.test');

    $first = (new Planner)->plan(new ZoneDesiredState('example.test', [
        record('A', 'a.example.test', '203.0.113.10'),
        record('A', 'b.example.test', '203.0.113.11'),
    ]), $observed);

    $second = (new Planner)->plan(new ZoneDesiredState('example.test', [
        record('A', 'b.example.test', '203.0.113.11'),
        record('A', 'a.example.test', '203.0.113.10'),
    ]), $observed);

    expect($first->hash)->toBe($second->hash)
        ->and($first->hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('changes the hash when the plan changes', function (): void {
    $observed = ZoneObservedState::empty('example.test');

    $first = (new Planner)->plan(new ZoneDesiredState('example.test', [record('A', 'a.example.test', '203.0.113.10')]), $observed);
    $second = (new Planner)->plan(new ZoneDesiredState('example.test', [record('A', 'a.example.test', '203.0.113.99')]), $observed);

    expect($first->hash)->not->toBe($second->hash);
});

it('refuses to plan one domain against another domain observations', function (): void {
    (new Planner)->plan(new ZoneDesiredState('example.test'), ZoneObservedState::empty('other.test'));
})->throws(InvalidArgumentException::class);

it('compares hostname content case-insensitively and without a trailing dot', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('CNAME', 'www.example.test', 'example.test')]),
        new ZoneObservedState('example.test', [record('CNAME', 'WWW.example.test', 'Example.Test.')]),
    );

    expect($plan->isConverged())->toBeTrue();
});
