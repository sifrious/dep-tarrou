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
        observed('example.test', $records),
    );

    expect($plan->isConverged())->toBeTrue()
        ->and($plan->operations)->toBeEmpty();
});

it('creates a record the zone does not have', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]),
        observed('example.test'),
    );

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->kind)->toBe(OperationKind::CreateRecord)
        ->and($plan->operations[0]->risk)->toBe(Risk::Additive)
        ->and($plan->operations[0]->before)->toBeNull();
});

it('updates a singular record whose content changed rather than replacing it', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('CNAME', 'www.example.test', 'example.test')]),
        observed('example.test', [record('CNAME', 'www.example.test', 'old.example.test')]),
    );

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->kind)->toBe(OperationKind::UpdateRecord)
        ->and($plan->operations[0]->before['content'])->toBe('old.example.test')
        ->and($plan->operations[0]->reason)->toContain('content');
});

it('treats a changed A record content as a create and a delete because both may coexist', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.11')]),
        observed('example.test', [record('A', 'example.test', '203.0.113.10')]),
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

    $observed = observed('example.test', [
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

    $observed = observed('example.test', [
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
    $unordered = observed('example.test', [], ['b.ns.test', 'a.ns.test']);

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
        observed('example.test', tlsMode: TlsMode::Flexible),
    );

    $declared = (new Planner)->plan(
        new ZoneDesiredState('example.test', tlsMode: TlsMode::FullStrict),
        observed('example.test', tlsMode: TlsMode::Flexible),
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

    $observed = observed('example.test', [record('A', 'old.example.test', '198.51.100.7')], ['z.ns.test']);

    $kinds = array_map(fn ($operation) => $operation->kind->value, (new Planner)->plan($desired, $observed)->operations);

    expect($kinds)->toBe([
        OperationKind::CreateRecord->value,
        OperationKind::SetTlsMode->value,
        OperationKind::DeleteRecord->value,
        OperationKind::SetNameservers->value,
    ]);
});

it('hashes the same inputs to the same plan regardless of declaration order', function (): void {
    $observed = observed('example.test');

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
    $observed = observed('example.test');

    $first = (new Planner)->plan(new ZoneDesiredState('example.test', [record('A', 'a.example.test', '203.0.113.10')]), $observed);
    $second = (new Planner)->plan(new ZoneDesiredState('example.test', [record('A', 'a.example.test', '203.0.113.99')]), $observed);

    expect($first->hash)->not->toBe($second->hash);
});

it('refuses to plan one domain against another domain observations', function (): void {
    (new Planner)->plan(new ZoneDesiredState('example.test'), observed('other.test'));
})->throws(InvalidArgumentException::class);

it('compares hostname content case-insensitively and without a trailing dot', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('CNAME', 'www.example.test', 'example.test')]),
        observed('example.test', [record('CNAME', 'WWW.example.test', 'Example.Test.')]),
    );

    expect($plan->isConverged())->toBeTrue();
});

it('cites the policy that produced each operation', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: [record('A', 'new.example.test', '203.0.113.11')],
        nameservers: ['a.ns.test'],
        tlsMode: TlsMode::FullStrict,
        managedTypes: [RecordType::A],
    );

    $plan = (new Planner)->plan(
        $desired,
        observed('example.test', [record('A', 'old.example.test', '198.51.100.7')], ['z.ns.test'], TlsMode::Flexible),
    );

    $policies = array_map(fn ($operation) => $operation->policy, $plan->operations);

    expect($policies)->toBe([
        'desired_state.declared',
        'policy.tls_mode',
        'desired_state.absent',
        'policy.authoritative_provider',
    ]);
});

it('gives a plan a stable identity derived from its contents', function (): void {
    $desired = new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]);

    $first = (new Planner)->plan($desired, observed('example.test'));
    $second = (new Planner)->plan($desired, observed('example.test'), new DateTimeImmutable('+1 hour'));

    expect($first->id())->toBe($second->id())
        ->and($first->id())->toStartWith('plan_');
});

it('will not apply a plan built on an observation with no time', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]),
        new ZoneObservedState('example.test'),
    );

    expect($plan->isApplicable(new DateTimeImmutable))->toBeFalse()
        ->and($plan->blockers(new DateTimeImmutable)[0])->toContain('carries no time');
});

it('will not apply a plan built on an observation that has aged out', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]),
        observed('example.test', observedAt: new DateTimeImmutable('-2 hours')),
    );

    expect($plan->isFresh(new DateTimeImmutable))->toBeFalse()
        ->and($plan->blockers(new DateTimeImmutable)[0])->toContain('older than 60 minutes');
});

it('refuses to delete on the strength of an observation that did not finish', function (): void {
    $desired = new ZoneDesiredState(
        domain: 'example.test',
        records: [record('A', 'example.test', '203.0.113.10')],
        managedTypes: [RecordType::A],
    );

    $plan = (new Planner)->plan(
        $desired,
        observed(
            'example.test',
            [record('A', 'example.test', '203.0.113.10'), record('A', 'stale.example.test', '198.51.100.7')],
            complete: false,
        ),
    );

    expect($plan->hasConflicts())->toBeTrue()
        ->and($plan->conflicts[0]->rule)->toBe('observation_incomplete')
        ->and($plan->isApplicable(new DateTimeImmutable))->toBeFalse();
});

it('does not raise an incomplete-observation conflict when nothing would be deleted', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('A', 'example.test', '203.0.113.10')]),
        observed('example.test', complete: false),
    );

    expect($plan->hasConflicts())->toBeFalse()
        ->and($plan->isApplicable(new DateTimeImmutable))->toBeTrue();
});

it('refuses to change a TLS mode nobody could read', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', tlsMode: TlsMode::FullStrict),
        observed('example.test'),
    );

    expect($plan->conflicts[0]->rule)->toBe('tls_mode_not_observed')
        ->and($plan->isApplicable(new DateTimeImmutable))->toBeFalse();
});

it('reports a CNAME that coexists with other records at the same name', function (): void {
    $plan = (new Planner)->plan(
        new ZoneDesiredState('example.test', [record('CNAME', 'www.example.test', 'example.test')]),
        observed('example.test', [record('TXT', 'www.example.test', 'verification=abc')]),
    );

    expect($plan->conflicts[0]->rule)->toBe('cname_coexists_with_other_records')
        ->and($plan->conflicts[0]->subject)->toBe('www.example.test');
});
