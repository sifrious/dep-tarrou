<?php

declare(strict_types=1);

use Sifrious\Tarrou\Apply\Applier;
use Sifrious\Tarrou\Apply\Approval;
use Sifrious\Tarrou\Apply\ApprovalMismatch;
use Sifrious\Tarrou\Apply\OperationStatus;
use Sifrious\Tarrou\Apply\Verifier;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\ZoneDesiredState;
use Sifrious\Tarrou\Plan\OperationKind;
use Sifrious\Tarrou\Plan\Planner;
use Sifrious\Tarrou\Testing\Fakes\InMemoryZone;

function desiredSite(array $records = null, array $nameservers = [], array $managed = null): ZoneDesiredState
{
    return new ZoneDesiredState(
        domain: 'example.test',
        records: $records ?? [
            record('A', 'example.test', '203.0.113.10'),
            record('CNAME', 'www.example.test', 'example.test'),
        ],
        nameservers: $nameservers,
        managedTypes: $managed ?? [RecordType::A, RecordType::CNAME],
    );
}

it('applies an approved plan and converges', function (): void {
    $zone = new InMemoryZone('example.test');
    $desired = desiredSite();
    $plan = (new Planner)->plan($desired, $zone->observe('example.test'));

    $result = (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone);

    expect($result->succeeded())->toBeTrue()
        ->and($result->countWith(OperationStatus::Applied))->toBe(2)
        ->and((new Verifier($zone))->verify($desired, $result)->converged())->toBeTrue();
});

it('refuses an approval issued for a different plan', function (): void {
    $zone = new InMemoryZone('example.test');
    $plan = (new Planner)->plan(desiredSite(), $zone->observe('example.test'));
    $other = (new Planner)->plan(
        desiredSite([record('A', 'example.test', '198.51.100.1'), record('CNAME', 'www.example.test', 'example.test')]),
        $zone->observe('example.test'),
    );

    (new Applier)->apply($plan, Approval::of($other, 'mary'), $zone);
})->throws(ApprovalMismatch::class);

it('refuses a destructive plan whose approval did not confirm the risk', function (): void {
    $zone = new InMemoryZone('example.test', [record('A', 'stale.example.test', '198.51.100.7')]);
    $plan = (new Planner)->plan(desiredSite(), $zone->observe('example.test'));

    expect($plan->requiresExplicitConfirmation())->toBeTrue();

    (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone);
})->throws(ApprovalMismatch::class);

it('applies a destructive plan once the risk is confirmed', function (): void {
    $zone = new InMemoryZone('example.test', [record('A', 'stale.example.test', '198.51.100.7')]);
    $desired = desiredSite();
    $plan = (new Planner)->plan($desired, $zone->observe('example.test'));

    $result = (new Applier)->apply($plan, Approval::of($plan, 'mary', confirmedHighRisk: true), $zone);

    expect($result->succeeded())->toBeTrue()
        ->and((new Verifier($zone))->verify($desired, $result)->converged())->toBeTrue();
});

it('changes nothing when the same plan is applied twice', function (): void {
    $zone = new InMemoryZone('example.test');
    $desired = desiredSite();
    $plan = (new Planner)->plan($desired, $zone->observe('example.test'));
    $approval = Approval::of($plan, 'mary');

    (new Applier)->apply($plan, $approval, $zone);
    $second = (new Applier)->apply($plan, $approval, $zone);

    expect($second->changedAnything())->toBeFalse()
        ->and($second->countWith(OperationStatus::AlreadyConverged))->toBe(2)
        ->and($second->succeeded())->toBeTrue();
});

it('halts on the first failure and skips the rest', function (): void {
    $zone = (new InMemoryZone('example.test'))->failingOn('A|example.test|203.0.113.10');
    $desired = desiredSite();
    $plan = (new Planner)->plan($desired, $zone->observe('example.test'));

    $result = (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone);

    expect($result->succeeded())->toBeFalse()
        ->and($result->countWith(OperationStatus::Failed))->toBe(1)
        ->and($result->countWith(OperationStatus::Skipped))->toBe(1)
        ->and($result->failures()[0]->detail)->toContain('rejected');
});

it('skips and halts when the provider cannot perform an operation', function (): void {
    $zone = (new InMemoryZone('example.test'))->withoutSupportFor(OperationKind::CreateRecord);
    $plan = (new Planner)->plan(desiredSite(), $zone->observe('example.test'));

    $result = (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone);

    expect($result->countWith(OperationStatus::Skipped))->toBe(2)
        ->and($result->outcomes[0]->detail)->toContain('does not support');
});

it('records what each applied operation replaced, newest first', function (): void {
    $zone = new InMemoryZone('example.test', [record('CNAME', 'www.example.test', 'old.example.test')]);
    $plan = (new Planner)->plan(desiredSite(), $zone->observe('example.test'));

    $rollback = (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone)->rollbackRecord();

    expect($rollback)->toHaveCount(2)
        ->and($rollback[0]['kind'])->toBe(OperationKind::UpdateRecord->value)
        ->and($rollback[0]['restore_to']['content'])->toBe('old.example.test')
        ->and($rollback[1]['restore_to'])->toBeNull();
});

it('reports a zone that did not converge even when the provider claimed success', function (): void {
    $zone = new InMemoryZone('example.test');
    $desired = desiredSite();
    $plan = (new Planner)->plan($desired, $zone->observe('example.test'));
    $result = (new Applier)->apply($plan, Approval::of($plan, 'mary'), $zone);

    $drifted = new InMemoryZone('example.test');

    expect((new Verifier($drifted))->verify($desired, $result)->converged())->toBeFalse();
});

it('rejects an approval that is not a sha256 digest', function (): void {
    new Approval('nope', 'mary', new DateTimeImmutable);
})->throws(InvalidArgumentException::class);
