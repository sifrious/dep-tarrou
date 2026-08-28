<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

/**
 * A reason this plan may not be applied, however sensible its operations look.
 *
 * A conflict is not a failed operation. It is a statement that the evidence the
 * plan rests on is not good enough to act on — the zone contradicts itself, or
 * the observation is missing the part the plan would change. Applying anyway
 * would be guessing with someone's DNS.
 */
final readonly class Conflict
{
    public const OBSERVATION_INCOMPLETE = 'observation_incomplete';

    public const TLS_MODE_NOT_OBSERVED = 'tls_mode_not_observed';

    public const CNAME_COEXISTS_WITH_OTHER_RECORDS = 'cname_coexists_with_other_records';

    public function __construct(
        public string $rule,
        public string $subject,
        public string $detail,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'subject' => $this->subject, 'detail' => $this->detail];
    }
}
