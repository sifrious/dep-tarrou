<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Contracts;

use Sifrious\Tarrou\Apply\OperationOutcome;
use Sifrious\Tarrou\Plan\Operation;
use Sifrious\Tarrou\Plan\OperationKind;

/**
 * A provider's ability to carry out one planned operation.
 *
 * Implementations are required to be idempotent: applying an operation whose
 * effect is already present must report `AlreadyConverged` rather than
 * repeating the change or failing.
 */
interface MutationCapability
{
    /**
     * @return list<OperationKind>
     */
    public function supportedOperations(): array;

    public function supports(OperationKind $kind): bool;

    public function apply(Operation $operation): OperationOutcome;
}
