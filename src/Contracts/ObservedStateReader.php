<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Contracts;

use Sifrious\Tarrou\Dns\ZoneObservedState;

/**
 * Reads the current state of a zone from whatever observed the provider.
 *
 * Tarrou never calls a provider API directly. Observation belongs to Aleph
 * connectors and accepted history belongs to Funes; this contract is the seam
 * between that evidence and desired-state planning.
 */
interface ObservedStateReader
{
    public function observe(string $domain): ZoneObservedState;
}
