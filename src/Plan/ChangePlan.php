<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

use DateTimeImmutable;

final readonly class ChangePlan
{
    /**
     * How long an observation may be relied on before a plan built from it has
     * to be rebuilt. DNS is not fast-moving, but a plan reviewed yesterday and
     * applied today is a plan applied to a zone nobody looked at.
     */
    public const DEFAULT_FRESHNESS_MINUTES = 60;

    /** @var list<Operation> */
    public array $operations;

    /** @var list<Conflict> */
    public array $conflicts;

    public string $hash;

    /**
     * @param  list<Operation>  $operations
     * @param  list<Conflict>  $conflicts
     */
    public function __construct(
        public string $domain,
        array $operations,
        public ?DateTimeImmutable $generatedAt = null,
        array $conflicts = [],
        public ?DateTimeImmutable $observedAt = null,
        public int $freshnessMinutes = self::DEFAULT_FRESHNESS_MINUTES,
    ) {
        $this->operations = array_values($operations);
        $this->conflicts = array_values($conflicts);
        $this->hash = PlanHash::of($domain, $this->operations);
    }

    /**
     * Stable identity. Two plans with the same contents for the same zone are
     * the same plan, which is what makes an approval transferable across a
     * re-render and nothing else.
     */
    public function id(): string
    {
        return 'plan_'.substr($this->hash, 0, 16);
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * A plan built from an observation older than the freshness window has to
     * be rebuilt. An observation with no time at all is never fresh: not
     * knowing when something was seen is worse than knowing it was seen a
     * while ago.
     */
    public function isFresh(DateTimeImmutable $now): bool
    {
        if ($this->observedAt === null) {
            return false;
        }

        return ($now->getTimestamp() - $this->observedAt->getTimestamp()) <= $this->freshnessMinutes * 60;
    }

    /**
     * @return list<string>
     */
    public function blockers(DateTimeImmutable $now): array
    {
        $blockers = [];

        foreach ($this->conflicts as $conflict) {
            $blockers[] = $conflict->rule.': '.$conflict->detail;
        }

        if (! $this->isFresh($now)) {
            $blockers[] = $this->observedAt === null
                ? 'The observation this plan rests on carries no time.'
                : sprintf(
                    'The observation this plan rests on is older than %d minutes.',
                    $this->freshnessMinutes,
                );
        }

        return $blockers;
    }

    public function isApplicable(DateTimeImmutable $now): bool
    {
        return $this->blockers($now) === [];
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function isConverged(): bool
    {
        return $this->isEmpty();
    }

    public function requiresExplicitConfirmation(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->risk->requiresExplicitConfirmation()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Operation>
     */
    public function withRisk(Risk $risk): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (Operation $operation): bool => $operation->risk === $risk,
        ));
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $summary = [];

        foreach (Risk::cases() as $risk) {
            $summary[$risk->value] = 0;
        }

        foreach ($this->operations as $operation) {
            $summary[$operation->risk->value]++;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'domain' => $this->domain,
            'hash' => $this->hash,
            'generated_at' => $this->generatedAt?->format(DATE_ATOM),
            'observed_at' => $this->observedAt?->format(DATE_ATOM),
            'freshness_minutes' => $this->freshnessMinutes,
            'conflicts' => array_map(
                static fn (Conflict $conflict): array => $conflict->toArray(),
                $this->conflicts,
            ),
            'summary' => $this->summary(),
            'operations' => array_map(
                static fn (Operation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }
}
