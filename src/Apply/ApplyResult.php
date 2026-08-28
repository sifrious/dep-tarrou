<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use Sifrious\Tarrou\Plan\ChangePlan;

final readonly class ApplyResult
{
    /** @var list<OperationOutcome> */
    public array $outcomes;

    /**
     * @param  list<OperationOutcome>  $outcomes
     */
    public function __construct(public ChangePlan $plan, array $outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function succeeded(): bool
    {
        return $this->countWith(OperationStatus::Failed) === 0
            && $this->countWith(OperationStatus::Skipped) === 0;
    }

    public function changedAnything(): bool
    {
        return $this->countWith(OperationStatus::Applied) > 0;
    }

    public function countWith(OperationStatus $status): int
    {
        return count(array_filter(
            $this->outcomes,
            static fn (OperationOutcome $outcome): bool => $outcome->status === $status,
        ));
    }

    /**
     * @return list<OperationOutcome>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (OperationOutcome $outcome): bool => $outcome->status === OperationStatus::Failed,
        ));
    }

    /**
     * Everything needed to put the zone back, newest change first.
     *
     * @return list<array<string, mixed>>
     */
    public function rollbackRecord(): array
    {
        $applied = array_values(array_filter(
            $this->outcomes,
            static fn (OperationOutcome $outcome): bool => $outcome->status->changedState(),
        ));

        return array_map(
            static fn (OperationOutcome $outcome): array => [
                'kind' => $outcome->operation->kind->value,
                'target' => $outcome->operation->target,
                'restore_to' => $outcome->capturedPriorState,
            ],
            array_reverse($applied),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_hash' => $this->plan->hash,
            'domain' => $this->plan->domain,
            'succeeded' => $this->succeeded(),
            'counts' => [
                OperationStatus::Applied->value => $this->countWith(OperationStatus::Applied),
                OperationStatus::AlreadyConverged->value => $this->countWith(OperationStatus::AlreadyConverged),
                OperationStatus::Skipped->value => $this->countWith(OperationStatus::Skipped),
                OperationStatus::Failed->value => $this->countWith(OperationStatus::Failed),
            ],
            'outcomes' => array_map(
                static fn (OperationOutcome $outcome): array => $outcome->toArray(),
                $this->outcomes,
            ),
        ];
    }
}
