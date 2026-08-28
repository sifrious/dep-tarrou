<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Tarrou\Dns\RecordSpec;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\ZoneDesiredState;
use Sifrious\Tarrou\Dns\ZoneObservedState;

/**
 * Produces a desired-versus-observed change plan for one zone.
 *
 * Two properties matter more than completeness:
 *
 * 1. Determinism. The same desired and observed states always produce the same
 *    operations in the same order, and therefore the same hash. An approval is
 *    bound to that hash, so non-determinism here would silently invalidate
 *    approvals.
 * 2. No inferred deletions. A record type the desired state does not manage is
 *    never proposed for deletion, so a partial desired state cannot strip a
 *    zone it does not fully describe.
 */
final class Planner
{
    /**
     * Execution order. Creations precede deletions so a replacement never
     * leaves a window with no answer, and delegation is last because it is the
     * operation whose effect is not immediately reversible.
     */
    private const KIND_ORDER = [
        OperationKind::CreateRecord->value => 0,
        OperationKind::UpdateRecord->value => 1,
        OperationKind::SetTlsMode->value => 2,
        OperationKind::DeleteRecord->value => 3,
        OperationKind::SetNameservers->value => 4,
    ];

    public function plan(
        ZoneDesiredState $desired,
        ZoneObservedState $observed,
        ?DateTimeImmutable $generatedAt = null,
        int $freshnessMinutes = ChangePlan::DEFAULT_FRESHNESS_MINUTES,
    ): ChangePlan {
        if ($desired->domain !== $observed->domain) {
            throw new InvalidArgumentException(
                "Cannot plan [{$desired->domain}] against observations for [{$observed->domain}]."
            );
        }

        $operations = [
            ...$this->recordOperations($desired, $observed),
            ...$this->tlsOperations($desired, $observed),
            ...$this->nameserverOperations($desired, $observed),
        ];

        return new ChangePlan(
            domain: $desired->domain,
            operations: $this->sort($operations),
            generatedAt: $generatedAt,
            conflicts: $this->conflicts($desired, $observed),
            observedAt: $observed->observedAt,
            freshnessMinutes: $freshnessMinutes,
        );
    }

    /**
     * Reasons this plan may not be applied at all.
     *
     * Each one is a case where the operations would be plausible and the
     * evidence behind them is not good enough to act on.
     *
     * @return list<Conflict>
     */
    private function conflicts(ZoneDesiredState $desired, ZoneObservedState $observed): array
    {
        $conflicts = [];

        /*
         * A partial observation is evidence about what exists and no evidence
         * about what does not, so it may not drive a deletion.
         */
        if (! $observed->complete) {
            foreach ($this->recordOperations($desired, $observed) as $operation) {
                if ($operation->kind === OperationKind::DeleteRecord) {
                    $conflicts[] = new Conflict(
                        Conflict::OBSERVATION_INCOMPLETE,
                        $desired->domain,
                        'The observation did not complete, so an absent record is not evidence that it does not exist.',
                    );

                    break;
                }
            }
        }

        /*
         * Changing a TLS mode nobody could read is a guess. The fix is a token
         * that can read the setting, not a plan that assumes it.
         */
        if ($desired->tlsMode !== null && $observed->tlsMode === null) {
            $conflicts[] = new Conflict(
                Conflict::TLS_MODE_NOT_OBSERVED,
                $desired->domain,
                'The desired state declares a TLS mode and the observation does not report one.',
            );
        }

        /*
         * A CNAME may not coexist with other records at the same name. Where
         * the zone already contradicts that, the plan says so rather than
         * quietly adding to the contradiction.
         */
        foreach ($this->cnameCollisions($desired, $observed) as $name) {
            $conflicts[] = new Conflict(
                Conflict::CNAME_COEXISTS_WITH_OTHER_RECORDS,
                $name,
                'A CNAME is declared or observed at a name that also carries other record types.',
            );
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    private function cnameCollisions(ZoneDesiredState $desired, ZoneObservedState $observed): array
    {
        $byName = [];

        foreach ([...$observed->records, ...$desired->records] as $record) {
            $byName[$record->normalizedName()][$record->type->value] = true;
        }

        $collisions = [];

        foreach ($byName as $name => $types) {
            if (isset($types[RecordType::CNAME->value]) && count($types) > 1) {
                $collisions[] = (string) $name;
            }
        }

        sort($collisions);

        return $collisions;
    }

    /**
     * @return list<Operation>
     */
    private function recordOperations(ZoneDesiredState $desired, ZoneObservedState $observed): array
    {
        $operations = [];
        $observedByIdentity = $observed->recordsByIdentity();
        $desiredIdentities = [];

        foreach ($desired->records as $record) {
            $identity = $record->identity();
            $desiredIdentities[$identity] = true;
            $current = $observedByIdentity[$identity] ?? null;

            if ($current === null) {
                $operations[] = new Operation(
                    kind: OperationKind::CreateRecord,
                    domain: $desired->domain,
                    target: $identity,
                    before: null,
                    after: $record->toArray(),
                    risk: OperationKind::CreateRecord->defaultRisk(),
                    reason: 'Declared in the desired state and absent from the zone.',
                    policy: Operation::POLICY_DESIRED_STATE,
                );

                continue;
            }

            if ($current->attributesMatch($record)) {
                continue;
            }

            $operations[] = new Operation(
                kind: OperationKind::UpdateRecord,
                domain: $desired->domain,
                target: $identity,
                before: $current->toArray(),
                after: $record->toArray(),
                risk: OperationKind::UpdateRecord->defaultRisk(),
                reason: $this->describeAttributeChange($current, $record),
                policy: Operation::POLICY_DESIRED_STATE,
            );
        }

        foreach ($observed->records as $current) {
            if (isset($desiredIdentities[$current->identity()])) {
                continue;
            }

            if (! $desired->manages($current->type)) {
                continue;
            }

            $operations[] = new Operation(
                kind: OperationKind::DeleteRecord,
                domain: $desired->domain,
                target: $current->identity(),
                before: $current->toArray(),
                after: null,
                risk: OperationKind::DeleteRecord->defaultRisk(),
                reason: "Present in the zone and absent from a desired state that manages {$current->type->value} records.",
                policy: Operation::POLICY_UNMANAGED_REMOVAL,
            );
        }

        return $operations;
    }

    /**
     * @return list<Operation>
     */
    private function tlsOperations(ZoneDesiredState $desired, ZoneObservedState $observed): array
    {
        if ($desired->tlsMode === null || $desired->tlsMode === $observed->tlsMode) {
            return [];
        }

        return [new Operation(
            kind: OperationKind::SetTlsMode,
            domain: $desired->domain,
            target: 'tls_mode',
            before: ['tls_mode' => $observed->tlsMode?->value],
            after: ['tls_mode' => $desired->tlsMode->value],
            risk: OperationKind::SetTlsMode->defaultRisk(),
            reason: 'Observed TLS mode differs from the declared policy.',
            policy: Operation::POLICY_TLS_MODE,
        )];
    }

    /**
     * @return list<Operation>
     */
    private function nameserverOperations(ZoneDesiredState $desired, ZoneObservedState $observed): array
    {
        if ($desired->nameservers === []) {
            return [];
        }

        $wanted = $desired->nameservers;
        $current = $observed->nameservers;

        sort($wanted);
        sort($current);

        if ($wanted === $current) {
            return [];
        }

        return [new Operation(
            kind: OperationKind::SetNameservers,
            domain: $desired->domain,
            target: 'nameservers',
            before: ['nameservers' => $current],
            after: ['nameservers' => $wanted],
            risk: OperationKind::SetNameservers->defaultRisk(),
            reason: 'Authoritative nameservers differ from the declared provider.',
            policy: Operation::POLICY_AUTHORITATIVE_PROVIDER,
        )];
    }

    private function describeAttributeChange(RecordSpec $current, RecordSpec $desired): string
    {
        $changes = [];

        if ($current->normalizedContent() !== $desired->normalizedContent()) {
            $changes[] = 'content';
        }

        if ($current->ttl !== $desired->ttl) {
            $changes[] = 'TTL';
        }

        if ($current->priority !== $desired->priority) {
            $changes[] = 'priority';
        }

        if ($current->proxied !== $desired->proxied) {
            $changes[] = 'proxy state';
        }

        return 'Differs from the desired state in '.implode(', ', $changes).'.';
    }

    /**
     * @param  list<Operation>  $operations
     * @return list<Operation>
     */
    private function sort(array $operations): array
    {
        usort($operations, static function (Operation $a, Operation $b): int {
            $order = self::KIND_ORDER[$a->kind->value] <=> self::KIND_ORDER[$b->kind->value];

            if ($order !== 0) {
                return $order;
            }

            return $a->target <=> $b->target;
        });

        return array_values($operations);
    }
}
