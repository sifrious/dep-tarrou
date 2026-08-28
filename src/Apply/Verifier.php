<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use Sifrious\Tarrou\Contracts\ObservedStateReader;
use Sifrious\Tarrou\Dns\ZoneDesiredState;
use Sifrious\Tarrou\Plan\Planner;

/**
 * Verification re-observes and re-plans. It does not trust the apply result,
 * because the failure this exists to catch is a provider that accepts an
 * operation and does not honour it.
 */
final class Verifier
{
    public function __construct(
        private readonly ObservedStateReader $reader,
        private readonly Planner $planner = new Planner,
    ) {}

    public function verify(ZoneDesiredState $desired, ApplyResult $result): ConvergenceReport
    {
        $observed = $this->reader->observe($desired->domain);

        return new ConvergenceReport($this->planner->plan($desired, $observed), $result);
    }
}
