<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

/**
 * The hash an approval is bound to.
 *
 * It covers the domain and the canonical form of every operation in order.
 * It does not cover generation time, reasons, or risk labels, so regenerating
 * the same plan from the same inputs yields the same hash and an approval
 * survives a re-render.
 */
final class PlanHash
{
    /**
     * @param  list<Operation>  $operations
     */
    public static function of(string $domain, array $operations): string
    {
        $canonical = json_encode(
            [
                'version' => 1,
                'domain' => $domain,
                'operations' => array_map(
                    static fn (Operation $operation): array => $operation->canonical(),
                    array_values($operations),
                ),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $canonical);
    }
}
