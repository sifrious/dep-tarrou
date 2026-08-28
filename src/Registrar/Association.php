<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Registrar;

/**
 * A domain's relationship to the portfolio, as decided rather than as guessed.
 *
 * `Unassigned` is a real state and the default. Nothing in this package infers
 * an association from a domain name, a nameserver, or a resemblance to a
 * project — that inference is exactly what C-19's scope fence forbids.
 */
enum Association: string
{
    case Live = 'live';
    case Committed = 'committed';
    case Parked = 'parked';
    case Undecided = 'undecided';
    case ApprovedForRelease = 'approved_for_release';
    case Unassigned = 'unassigned';

    /**
     * Whether losing this domain would cost something. Auto-renew being off is
     * a finding for these and merely a fact for the others.
     */
    public function mustNotLapse(): bool
    {
        return $this === self::Live || $this === self::Committed;
    }

    public function isDecided(): bool
    {
        return $this !== self::Undecided && $this !== self::Unassigned;
    }
}
