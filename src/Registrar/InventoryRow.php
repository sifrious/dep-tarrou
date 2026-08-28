<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Registrar;

use DateTimeImmutable;

final readonly class InventoryRow
{
    /**
     * @param  list<string>  $findings
     */
    public function __construct(
        public DomainRegistration $registration,
        public Association $association,
        public RenewalRisk $risk,
        public ?int $daysUntilExpiry,
        public bool $observationIsStale,
        public array $findings,
    ) {}

    public function domain(): string
    {
        return $this->registration->domain;
    }

    public function needsAction(): bool
    {
        return $this->risk->needsAction() || $this->observationIsStale || $this->findings !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->registration->domain,
            'registrar' => $this->registration->registrar,
            'association' => $this->association->value,
            'risk' => $this->risk->value,
            'expires_at' => $this->registration->expiresAt?->format(DateTimeImmutable::ATOM),
            'days_until_expiry' => $this->daysUntilExpiry,
            'auto_renew' => $this->registration->autoRenew,
            'privacy' => $this->registration->privacy,
            'nameservers' => $this->registration->nameservers,
            'uses_registrar_dns' => $this->registration->usesRegistrarDns,
            'observed_at' => $this->registration->observedAt->format(DateTimeImmutable::ATOM),
            'observation_is_stale' => $this->observationIsStale,
            'findings' => $this->findings,
            'needs_action' => $this->needsAction(),
        ];
    }
}
