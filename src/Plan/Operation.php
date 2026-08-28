<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

/**
 * One intended change. `before` is the captured prior state and is what a
 * rollback plan is built from; `after` is what the operation asserts.
 */
final readonly class Operation
{
    /** The desired state declared this record. */
    public const POLICY_DESIRED_STATE = 'desired_state.declared';

    /** The desired state manages this type and does not declare this record. */
    public const POLICY_UNMANAGED_REMOVAL = 'desired_state.absent';

    /** The declared TLS policy differs from what the provider reports. */
    public const POLICY_TLS_MODE = 'policy.tls_mode';

    /** The declared authoritative provider differs from the current delegation. */
    public const POLICY_AUTHORITATIVE_PROVIDER = 'policy.authoritative_provider';

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public OperationKind $kind,
        public string $domain,
        public string $target,
        public ?array $before,
        public ?array $after,
        public Risk $risk,
        public string $reason,
        public string $policy = self::POLICY_DESIRED_STATE,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'domain' => $this->domain,
            'target' => $this->target,
            'before' => $this->before,
            'after' => $this->after,
            'risk' => $this->risk->value,
            'reason' => $this->reason,
            'policy' => $this->policy,
        ];
    }

    /**
     * Canonical form used for hashing. The reason is deliberately excluded:
     * rewording an explanation must not invalidate an approval.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return [
            'kind' => $this->kind->value,
            'domain' => $this->domain,
            'target' => $this->target,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
