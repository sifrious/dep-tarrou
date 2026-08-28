<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Policy;

/**
 * What is known about a domain's role, as observed rather than as intended.
 */
final readonly class DomainStanding
{
    public function __construct(
        public string $domain,
        public bool $attachedToSite = false,
        public bool $siteIsLiveOrCommitted = false,
        public bool $expired = false,
    ) {}

    public function servesPublicTraffic(): bool
    {
        return ! $this->expired && $this->attachedToSite && $this->siteIsLiveOrCommitted;
    }

    /**
     * C-07, step 5: do not migrate inactive domains merely because they exist.
     * Eligibility is a positive assertion, never the absence of a reason to skip.
     */
    public function eligibleForMigration(): bool
    {
        return $this->servesPublicTraffic();
    }
}
