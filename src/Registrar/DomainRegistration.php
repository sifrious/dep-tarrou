<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Registrar;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One registrar observation, as observed.
 *
 * Every field is nullable except the domain and the observation time, because
 * a registrar that did not report a field has not told us it is false. The
 * `observedAt` is mandatory for the reason C-19 gives: a 2026-04-19 fact about
 * a 2026-06-04 expiry was still true when it was written and is worthless now.
 */
final readonly class DomainRegistration
{
    /** @var list<string> */
    public array $nameservers;

    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $domain,
        public DateTimeImmutable $observedAt,
        public ?string $registrar = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?bool $autoRenew = null,
        public ?string $privacy = null,
        array $nameservers = [],
        public ?bool $usesRegistrarDns = null,
        public ?string $sourceReference = null,
    ) {
        if (trim($domain) === '') {
            throw new InvalidArgumentException('A registration requires a domain.');
        }

        $this->nameservers = array_values(array_map(
            static fn (string $host): string => rtrim(strtolower(trim($host)), '.'),
            $nameservers,
        ));
    }

    /**
     * Build from an Aleph connector's normalized domain extension payload.
     * Unknown or absent fields stay null; nothing is defaulted into existence.
     *
     * @param  array<string, mixed>  $normalized
     */
    public static function fromNormalized(
        array $normalized,
        DateTimeImmutable $observedAt,
        ?string $registrar = null,
    ): self {
        $expires = isset($normalized['expires_at']) && is_string($normalized['expires_at'])
            ? new DateTimeImmutable($normalized['expires_at'])
            : null;

        return new self(
            domain: rtrim(strtolower(trim((string) ($normalized['domain'] ?? ''))), '.'),
            observedAt: $observedAt,
            registrar: $registrar,
            expiresAt: $expires,
            autoRenew: isset($normalized['auto_renew']) ? (bool) $normalized['auto_renew'] : null,
            privacy: isset($normalized['privacy']) && is_string($normalized['privacy'])
                ? $normalized['privacy']
                : null,
            nameservers: isset($normalized['name_servers']) && is_array($normalized['name_servers'])
                ? array_values(array_map(strval(...), $normalized['name_servers']))
                : [],
            usesRegistrarDns: isset($normalized['uses_registrar_dns'])
                ? (bool) $normalized['uses_registrar_dns']
                : null,
            sourceReference: isset($normalized['provider_id']) && $normalized['provider_id'] !== null
                ? (string) $normalized['provider_id']
                : null,
        );
    }

    public function daysUntilExpiry(DateTimeImmutable $asOf): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) $asOf->setTime(0, 0)->diff($this->expiresAt->setTime(0, 0))->format('%r%a');
    }

    public function observationAgeInDays(DateTimeImmutable $asOf): int
    {
        return (int) $this->observedAt->setTime(0, 0)->diff($asOf->setTime(0, 0))->format('%a');
    }

    /**
     * A registration whose privacy state the registrar reported as unavailable
     * is not an oversight: several ccTLDs do not offer WHOIS privacy at all.
     */
    public function privacyIsAvoidablyOff(): bool
    {
        return $this->privacy === 'disabled';
    }
}
