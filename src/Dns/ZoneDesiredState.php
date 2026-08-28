<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Dns;

use InvalidArgumentException;

/**
 * The intended state of one zone.
 *
 * `managedTypes` fences the plan: a record type absent from this list is never
 * proposed for deletion, so a partially-declared zone cannot silently strip
 * records the desired state does not know about.
 */
final readonly class ZoneDesiredState
{
    /** @var list<RecordSpec> */
    public array $records;

    /** @var list<string> */
    public array $nameservers;

    /** @var list<RecordType> */
    public array $managedTypes;

    /**
     * @param  list<RecordSpec>  $records
     * @param  list<string>  $nameservers
     * @param  list<RecordType>|null  $managedTypes
     */
    public function __construct(
        public string $domain,
        array $records = [],
        array $nameservers = [],
        public ?TlsMode $tlsMode = null,
        ?array $managedTypes = null,
    ) {
        if (trim($domain) === '') {
            throw new InvalidArgumentException('A zone requires a domain.');
        }

        $this->records = array_values($records);
        $this->nameservers = array_values(array_map(
            static fn (string $host): string => rtrim(strtolower(trim($host)), '.'),
            $nameservers,
        ));

        $this->managedTypes = $managedTypes !== null
            ? array_values($managedTypes)
            : array_values(array_unique(array_map(
                static fn (RecordSpec $record): RecordType => $record->type,
                $this->records,
            ), SORT_REGULAR));

        $this->assertNoConflictingSingularRecords();
    }

    public function manages(RecordType $type): bool
    {
        return in_array($type, $this->managedTypes, true);
    }

    private function assertNoConflictingSingularRecords(): void
    {
        $seen = [];

        foreach ($this->records as $record) {
            if (! $record->type->isSingular()) {
                continue;
            }

            $key = $record->identity();

            if (isset($seen[$key])) {
                throw new InvalidArgumentException(
                    "Two {$record->type->value} records declared for [{$record->normalizedName()}]; only one may exist."
                );
            }

            $seen[$key] = true;
        }
    }
}
