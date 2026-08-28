<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Registrar;

enum RenewalRisk: string
{
    /** The registration has already lapsed. */
    case Lapsed = 'lapsed';

    /** Inside the horizon with no auto-renew: it will lapse unless someone acts. */
    case Unprotected = 'unprotected';

    /** Auto-renew is off on a domain that must not lapse, but the horizon is not reached. */
    case Unprotected_Later = 'unprotected_later';

    /** Inside the horizon with auto-renew on: expected to renew itself. */
    case Renewing = 'renewing';

    /** No expiry was observed, so nothing can be said. */
    case Unknown = 'unknown';

    case None = 'none';

    public function needsAction(): bool
    {
        return match ($this) {
            self::Lapsed, self::Unprotected, self::Unprotected_Later, self::Unknown => true,
            self::Renewing, self::None => false,
        };
    }

    /**
     * Most consequential first, so a view that sorts by this puts the domains
     * that can still be saved above the ones that are merely interesting.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Lapsed => 0,
            self::Unprotected => 1,
            self::Unprotected_Later => 2,
            self::Unknown => 3,
            self::Renewing => 4,
            self::None => 5,
        };
    }
}
