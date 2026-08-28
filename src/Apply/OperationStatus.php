<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

enum OperationStatus: string
{
    /** The provider changed state as the operation asked. */
    case Applied = 'applied';

    /** The provider already reported the intended state; nothing was changed. */
    case AlreadyConverged = 'already_converged';

    /** Not attempted, because an earlier operation failed or the capability does not support it. */
    case Skipped = 'skipped';

    /** Attempted and rejected by the provider. */
    case Failed = 'failed';

    public function changedState(): bool
    {
        return $this === self::Applied;
    }
}
