<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Policy;

use InvalidArgumentException;
use Sifrious\Tarrou\Dns\RecordSpec;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneDesiredState;

/**
 * The record set every new site gets, so that "what should this domain look
 * like" is answered by a function rather than by reading another zone.
 *
 * Apex is an A record at the origin address; `www` is a CNAME to the apex.
 * Both are proxied when the edge provider is authoritative, and proxying
 * forces Full (strict).
 */
final readonly class StandardRecordSet
{
    public function __construct(
        public string $originAddress,
        public int $ttl = 3600,
        public bool $proxied = true,
    ) {
        if (filter_var($originAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("[{$originAddress}] is not a valid origin address.");
        }
    }

    /**
     * @return list<RecordSpec>
     */
    public function records(string $domain): array
    {
        $apex = rtrim(strtolower(trim($domain)), '.');

        return [
            new RecordSpec(
                type: RecordType::A,
                name: $apex,
                content: $this->originAddress,
                ttl: $this->ttl,
                proxied: $this->proxied,
            ),
            new RecordSpec(
                type: RecordType::CNAME,
                name: 'www.'.$apex,
                content: $apex,
                ttl: $this->ttl,
                proxied: $this->proxied,
            ),
        ];
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function desiredState(string $domain, array $nameservers = []): ZoneDesiredState
    {
        return new ZoneDesiredState(
            domain: rtrim(strtolower(trim($domain)), '.'),
            records: $this->records($domain),
            nameservers: $nameservers,
            tlsMode: $this->proxied ? TlsMode::FullStrict : null,
            managedTypes: [RecordType::A, RecordType::CNAME],
        );
    }
}
