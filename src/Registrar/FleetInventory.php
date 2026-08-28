<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Registrar;

use DateTimeImmutable;

/**
 * The read model behind the domain fleet view.
 *
 * It answers one question per domain — is anything about this registration
 * going to cost me something — and refuses to answer it from stale evidence.
 * C-19's live problem was not that the facts were unknown; it was that facts
 * observed on 2026-04-19 were still being read in August as though they were
 * current. An observation older than the staleness window is reported as stale
 * and its risk is not presented as reliable.
 */
final readonly class FleetInventory
{
    public const HORIZON_DAYS = 60;

    public const STALE_AFTER_DAYS = 7;

    public function __construct(
        private int $horizonDays = self::HORIZON_DAYS,
        private int $staleAfterDays = self::STALE_AFTER_DAYS,
    ) {}

    /**
     * @param  list<DomainRegistration>  $registrations
     * @param  array<string, Association>  $associations  keyed by domain
     * @return list<InventoryRow>
     */
    public function rows(array $registrations, array $associations, DateTimeImmutable $asOf): array
    {
        $rows = [];

        foreach ($this->deduplicate($registrations) as $registration) {
            $association = $associations[$registration->domain] ?? Association::Unassigned;

            $rows[] = new InventoryRow(
                registration: $registration,
                association: $association,
                risk: $this->risk($registration, $association, $asOf),
                daysUntilExpiry: $registration->daysUntilExpiry($asOf),
                observationIsStale: $registration->observationAgeInDays($asOf) > $this->staleAfterDays,
                findings: $this->findings($registration, $association, $asOf),
            );
        }

        usort($rows, static function (InventoryRow $a, InventoryRow $b): int {
            return [$a->risk->weight(), $a->domain()] <=> [$b->risk->weight(), $b->domain()];
        });

        return $rows;
    }

    /**
     * @param  list<InventoryRow>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        $summary = ['total' => count($rows), 'needs_action' => 0, 'stale' => 0, 'unassigned' => 0];

        foreach (RenewalRisk::cases() as $risk) {
            $summary[$risk->value] = 0;
        }

        foreach ($rows as $row) {
            $summary[$row->risk->value]++;
            $summary['needs_action'] += $row->needsAction() ? 1 : 0;
            $summary['stale'] += $row->observationIsStale ? 1 : 0;
            $summary['unassigned'] += $row->association === Association::Unassigned ? 1 : 0;
        }

        return $summary;
    }

    private function risk(
        DomainRegistration $registration,
        Association $association,
        DateTimeImmutable $asOf,
    ): RenewalRisk {
        $days = $registration->daysUntilExpiry($asOf);

        if ($days === null) {
            return RenewalRisk::Unknown;
        }

        if ($days < 0) {
            return RenewalRisk::Lapsed;
        }

        $autoRenewOff = $registration->autoRenew === false;
        $withinHorizon = $days <= $this->horizonDays;

        return match (true) {
            $autoRenewOff && $withinHorizon => RenewalRisk::Unprotected,
            $autoRenewOff && $association->mustNotLapse() => RenewalRisk::Unprotected_Later,
            $withinHorizon => RenewalRisk::Renewing,
            default => RenewalRisk::None,
        };
    }

    /**
     * Findings are stated, never inferred, and each one names what to do.
     *
     * @return list<string>
     */
    private function findings(
        DomainRegistration $registration,
        Association $association,
        DateTimeImmutable $asOf,
    ): array {
        $findings = [];

        if ($registration->autoRenew === null) {
            $findings[] = 'Auto-renew was not reported by the registrar.';
        }

        if ($registration->autoRenew === false && $association->mustNotLapse()) {
            $findings[] = 'Auto-renew is off on a domain attached to a live or committed site.';
        }

        if ($registration->expiresAt === null) {
            $findings[] = 'No expiry date was observed.';
        }

        if ($association === Association::Unassigned) {
            $findings[] = 'No project or site association has been decided.';
        }

        if ($registration->privacyIsAvoidablyOff()) {
            $findings[] = 'WHOIS privacy is available and switched off.';
        }

        if ($registration->observationAgeInDays($asOf) > $this->staleAfterDays) {
            $findings[] = sprintf(
                'Observed %d days ago; re-observe before acting on it.',
                $registration->observationAgeInDays($asOf),
            );
        }

        return $findings;
    }

    /**
     * A domain appears exactly once. Where the same domain is observed twice,
     * the most recent observation wins — an older one is not evidence about now.
     *
     * @param  list<DomainRegistration>  $registrations
     * @return list<DomainRegistration>
     */
    private function deduplicate(array $registrations): array
    {
        $latest = [];

        foreach ($registrations as $registration) {
            $existing = $latest[$registration->domain] ?? null;

            if ($existing === null || $registration->observedAt > $existing->observedAt) {
                $latest[$registration->domain] = $registration;
            }
        }

        return array_values($latest);
    }
}
