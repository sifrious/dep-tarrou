<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Dns;

use InvalidArgumentException;

enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case NS = 'NS';
    case SRV = 'SRV';
    case CAA = 'CAA';

    public static function parse(string $value): self
    {
        $type = self::tryFrom(strtoupper(trim($value)));

        if ($type === null) {
            throw new InvalidArgumentException("Unsupported DNS record type [{$value}].");
        }

        return $type;
    }

    /**
     * Types whose content is a hostname and therefore compared case-insensitively
     * with a trailing dot removed.
     */
    public function hasHostnameContent(): bool
    {
        return in_array($this, [self::CNAME, self::MX, self::NS, self::SRV], true);
    }

    /**
     * Only one record of these types may exist at a given name.
     */
    public function isSingular(): bool
    {
        return $this === self::CNAME;
    }
}
