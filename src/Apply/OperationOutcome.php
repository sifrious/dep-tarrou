<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use Sifrious\Tarrou\Plan\Operation;

final readonly class OperationOutcome
{
    /**
     * @param  array<string, mixed>|null  $capturedPriorState
     */
    public function __construct(
        public Operation $operation,
        public OperationStatus $status,
        public ?string $detail = null,
        public ?array $capturedPriorState = null,
    ) {}

    public static function applied(Operation $operation, ?array $capturedPriorState = null): self
    {
        return new self($operation, OperationStatus::Applied, null, $capturedPriorState ?? $operation->before);
    }

    public static function alreadyConverged(Operation $operation): self
    {
        return new self($operation, OperationStatus::AlreadyConverged, 'The provider already reports this state.');
    }

    public static function skipped(Operation $operation, string $detail): self
    {
        return new self($operation, OperationStatus::Skipped, $detail);
    }

    public static function failed(Operation $operation, string $detail): self
    {
        return new self($operation, OperationStatus::Failed, $detail);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->toArray(),
            'status' => $this->status->value,
            'detail' => $this->detail,
            'captured_prior_state' => $this->capturedPriorState,
        ];
    }
}
