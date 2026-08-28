<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Apply;

use RuntimeException;

final class ApprovalMismatch extends RuntimeException
{
    public static function hash(string $approved, string $actual): self
    {
        return new self(
            "The approval covers plan [{$approved}] but the plan presented is [{$actual}]. ".
            'Re-review the current plan; approval is not transferable between plans.'
        );
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function blocked(array $blockers): self
    {
        return new self(
            "This plan may not be applied:\n- ".implode("\n- ", $blockers)."\n".
            'Re-observe the zone and rebuild the plan.'
        );
    }

    public static function unconfirmedHighRisk(): self
    {
        return new self(
            'This plan contains destructive or delegating operations and the approval did not confirm them.'
        );
    }
}
