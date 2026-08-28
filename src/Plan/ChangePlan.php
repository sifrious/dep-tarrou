<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

use DateTimeImmutable;

final readonly class ChangePlan
{
    /** @var list<Operation> */
    public array $operations;

    public string $hash;

    /**
     * @param  list<Operation>  $operations
     */
    public function __construct(
        public string $domain,
        array $operations,
        public ?DateTimeImmutable $generatedAt = null,
    ) {
        $this->operations = array_values($operations);
        $this->hash = PlanHash::of($domain, $this->operations);
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
            'domain' => $this->domain,
            'hash' => $this->hash,
            'generated_at' => $this->generatedAt?->format(DATE_ATOM),
            'summary' => $this->summary(),
            'operations' => array_map(
                static fn (Operation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }
}
