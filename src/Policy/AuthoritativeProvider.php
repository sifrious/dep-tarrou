<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Policy;

enum AuthoritativeProvider: string
{
    case Registrar = 'registrar';
    case Edge = 'edge';

    /**
     * The rule from C-07, stated once so nothing else has to remember it:
     * a domain that is actually serving something moves to the edge provider,
     * for the API, the CDN and the Workers option. A domain that serves
     * nothing stays where it is registered.
     */
    public static function forStanding(DomainStanding $standing): self
    {
        return $standing->servesPublicTraffic() ? self::Edge : self::Registrar;
    }
}
