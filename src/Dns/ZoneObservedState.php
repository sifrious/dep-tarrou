<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Dns;

/**
 * What a provider currently reports for one zone.
 *
 * Observation is never inferred here. A null `tlsMode` means the provider did
 * not report one, which is different from reporting `off`.
 */
final readonly class ZoneObservedState
{
    /** @var list<RecordSpec> */
    public array $records;

    /** @var list<string> */
    public array $nameservers;

    /**
     * @param  list<RecordSpec>  $records
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $domain,
        array $records = [],
        array $nameservers = [],
        public ?TlsMode $tlsMode = null,
        public ?string $observedAt = null,
    ) {
        $this->records = array_values($records);
        $this->nameservers = array_values(array_map(
            static fn (string $host): string => rtrim(strtolower(trim($host)), '.'),
            $nameservers,
        ));
    }

    /**
     * @return array<string, RecordSpec>
     */
    public function recordsByIdentity(): array
    {
        $indexed = [];

        foreach ($this->records as $record) {
            $indexed[$record->identity()] = $record;
        }

        return $indexed;
    }

    public static function empty(string $domain): self
    {
        return new self($domain);
    }
}
