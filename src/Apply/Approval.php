<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Tarrou\Plan\ChangePlan;

/**
 * Consent to one exact plan.
 *
 * An approval carries the plan hash rather than a reference to a plan object,
 * so a plan that is regenerated with different contents cannot inherit consent
 * given to an earlier one.
 */
final readonly class Approval
{
    public function __construct(
        public string $planHash,
        public string $approvedBy,
        public DateTimeImmutable $approvedAt,
        public bool $confirmedHighRisk = false,
    ) {
        if (! preg_match('/^[0-9a-f]{64}$/', $planHash)) {
            throw new InvalidArgumentException('A plan hash must be a sha256 digest.');
        }
    }

    public static function of(
        ChangePlan $plan,
        string $approvedBy,
        ?DateTimeImmutable $approvedAt = null,
        bool $confirmedHighRisk = false,
    ): self {
        return new self($plan->hash, $approvedBy, $approvedAt ?? new DateTimeImmutable, $confirmedHighRisk);
    }

    public function covers(ChangePlan $plan): bool
    {
        return hash_equals($this->planHash, $plan->hash);
    }
}
