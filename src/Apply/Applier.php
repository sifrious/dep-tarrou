<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use DateTimeImmutable;
use Sifrious\Tarrou\Contracts\MutationCapability;
use Sifrious\Tarrou\Plan\ChangePlan;
use Sifrious\Tarrou\Plan\Operation;
use Throwable;

/**
 * Carries out an approved plan.
 *
 * Three rules hold regardless of provider:
 *
 * 1. Consent is bound to a hash. A plan whose hash differs from the approval
 *    is refused, not re-approved.
 * 2. Execution halts on the first failure. A partially applied zone with a
 *    recorded stopping point is recoverable; one where later operations ran
 *    against an unexpected state is not.
 * 3. Every applied operation records the prior state it replaced, so the
 *    result is also a rollback plan.
 */
final class Applier
{
    public function apply(
        ChangePlan $plan,
        Approval $approval,
        MutationCapability $capability,
        ?DateTimeImmutable $now = null,
    ): ApplyResult {
        $now ??= new DateTimeImmutable;

        if (! $approval->covers($plan)) {
            throw ApprovalMismatch::hash($approval->planHash, $plan->hash);
        }

        if ($plan->requiresExplicitConfirmation() && ! $approval->confirmedHighRisk) {
            throw ApprovalMismatch::unconfirmedHighRisk();
        }

        /*
         * Conflicts and stale evidence are refusals, not warnings. A plan whose
         * observation has aged out describes a zone nobody has looked at
         * recently, and applying it is acting on a memory.
         */
        if (! $plan->isApplicable($now)) {
            throw ApprovalMismatch::blocked($plan->blockers($now));
        }

        $outcomes = [];
        $halted = false;

        foreach ($plan->operations as $operation) {
            if ($halted) {
                $outcomes[] = OperationOutcome::skipped($operation, 'An earlier operation failed; execution halted.');

                continue;
            }

            if (! $capability->supports($operation->kind)) {
                $outcomes[] = OperationOutcome::skipped(
                    $operation,
                    "The provider does not support [{$operation->kind->value}]."
                );
                $halted = true;

                continue;
            }

            $outcome = $this->attempt($capability, $operation);
            $outcomes[] = $outcome;

            if ($outcome->status === OperationStatus::Failed) {
                $halted = true;
            }
        }

        return new ApplyResult($plan, $outcomes);
    }

    private function attempt(MutationCapability $capability, Operation $operation): OperationOutcome
    {
        try {
            return $capability->apply($operation);
        } catch (Throwable $exception) {
            return OperationOutcome::failed($operation, $exception->getMessage());
        }
    }
}
