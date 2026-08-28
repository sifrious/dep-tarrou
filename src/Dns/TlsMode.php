<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Dns;

enum TlsMode: string
{
    case Off = 'off';
    case Flexible = 'flexible';
    case Full = 'full';
    case FullStrict = 'full_strict';

    /**
     * Flexible terminates TLS at the edge and speaks plaintext to the origin.
     * In front of an HTTPS origin that produces a redirect loop, so the policy
     * in C-07 rejects it rather than warning about it.
     */
    public function isAcceptableForProxiedHttpsOrigin(): bool
    {
        return $this === self::FullStrict;
    }
}
