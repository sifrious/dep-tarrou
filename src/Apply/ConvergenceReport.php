<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use Sifrious\Tarrou\Plan\ChangePlan;

/**
 * The answer to "did it work", asked of the provider rather than of the
 * apply result. A residual plan that is not empty means the zone did not
 * converge, whatever the outcomes claimed.
 */
final readonly class ConvergenceReport
{
    public function __construct(
        public ChangePlan $residual,
        public ApplyResult $result,
    ) {}

    public function converged(): bool
    {
        return $this->residual->isConverged();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'converged' => $this->converged(),
            'residual' => $this->residual->toArray(),
            'result' => $this->result->toArray(),
        ];
    }
}
