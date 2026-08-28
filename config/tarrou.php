<?php

declare(strict_types=1);

use Sifrious\Tarrou\Dns\TlsMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Standard record set
    |--------------------------------------------------------------------------
    |
    | The apex target every new site points at, and the attributes applied to
    | the apex and www records. These are policy, not secrets; provider
    | credentials never appear here.
    |
    */

    'standard_record_set' => [
        'origin_address' => env('TARROU_ORIGIN_ADDRESS'),
        'ttl' => (int) env('TARROU_RECORD_TTL', 3600),
        'proxied' => (bool) env('TARROU_RECORDS_PROXIED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | TLS
    |--------------------------------------------------------------------------
    |
    | Proxied HTTPS origins require Full (strict). Flexible is not configurable
    | because it is not a supported choice; see Sifrious\Tarrou\Policy\DnsPolicy.
    |
    */

    'tls_mode' => TlsMode::FullStrict->value,

    /*
    |--------------------------------------------------------------------------
    | Authoritative nameservers
    |--------------------------------------------------------------------------
    |
    | The nameserver sets a zone may be delegated to, keyed by the authoritative
    | provider role. An empty set means a plan never proposes a delegation.
    |
    */

    'nameservers' => [
        'registrar' => [],
        'edge' => [],
    ],

];
