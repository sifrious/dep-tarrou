<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

/**
 * One intended change. `before` is the captured prior state and is what a
 * rollback plan is built from; `after` is what the operation asserts.
 */
final readonly class Operation
{
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
