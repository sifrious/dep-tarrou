<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

/**
 * Risk is a property of the operation, not of the operator's confidence.
 * The labels exist so a review screen can group operations without
 * re-deriving what each one costs if it is wrong.
 */
enum Risk: string
{
    /** Nothing resolvable today stops resolving. */
    case Additive = 'additive';

    /** An existing answer changes; the previous value is recoverable from the captured prior state. */
    case Replacing = 'replacing';

    /** An existing answer disappears. Recovery requires re-creating the record. */
    case Destructive = 'destructive';

    /** Authority over the whole zone moves. Propagation is not instant and rollback is not immediate. */
    case Delegating = 'delegating';

    public function requiresExplicitConfirmation(): bool
    {
        return $this === self::Destructive || $this === self::Delegating;
    }

    /**
     * Ordering used for presentation: safest first, so a reviewer reads the
     * consequential operations last rather than scrolling past them.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Additive => 0,
            self::Replacing => 1,
            self::Destructive => 2,
            self::Delegating => 3,
        };
    }
}
