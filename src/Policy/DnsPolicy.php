<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Policy;

use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneDesiredState;

/**
 * The rules a desired state must satisfy before it is allowed to become a plan.
 *
 * These are rejections, not warnings. Flexible TLS in front of an HTTPS origin
 * produces a redirect loop; a desired state that asks for it is wrong, and the
 * cheapest place to find that out is before anything is proposed.
 */
final class DnsPolicy
{
    public const RULE_PROXIED_REQUIRES_FULL_STRICT = 'proxied_requires_full_strict';

    public const RULE_APEX_REQUIRED = 'apex_record_required';

    public const RULE_WWW_REQUIRED = 'www_record_required';

    public const RULE_NO_CNAME_AT_APEX = 'no_cname_at_apex';

    /**
     * @return list<PolicyViolation>
     */
    public function violations(ZoneDesiredState $desired): array
    {
        $violations = [];
        $apex = rtrim(strtolower(trim($desired->domain)), '.');
        $hasProxiedRecord = false;
        $hasApex = false;
        $hasWww = false;

        foreach ($desired->records as $record) {
            $name = $record->normalizedName();

            if ($record->proxied === true) {
                $hasProxiedRecord = true;
            }

            if ($name === $apex) {
                $hasApex = true;

                if ($record->type === RecordType::CNAME) {
                    $violations[] = new PolicyViolation(
                        self::RULE_NO_CNAME_AT_APEX,
                        $apex,
                        'A CNAME at the apex conflicts with the zone\'s own SOA and NS records.',
                    );
                }
            }

            if ($name === 'www.'.$apex) {
                $hasWww = true;
            }
        }

        if ($hasProxiedRecord && $desired->tlsMode !== null && ! $desired->tlsMode->isAcceptableForProxiedHttpsOrigin()) {
            $violations[] = new PolicyViolation(
                self::RULE_PROXIED_REQUIRES_FULL_STRICT,
                $apex,
                sprintf(
                    'Proxied records require TLS mode [%s]; this state declares [%s].',
                    TlsMode::FullStrict->value,
                    $desired->tlsMode->value,
                ),
            );
        }

        if ($hasProxiedRecord && $desired->tlsMode === null) {
            $violations[] = new PolicyViolation(
                self::RULE_PROXIED_REQUIRES_FULL_STRICT,
                $apex,
                'Proxied records were declared without a TLS mode; the policy requires an explicit Full (strict).',
            );
        }

        if (! $hasApex) {
            $violations[] = new PolicyViolation(
                self::RULE_APEX_REQUIRED,
                $apex,
                'The standard record set requires an apex record.',
            );
        }

        if (! $hasWww) {
            $violations[] = new PolicyViolation(
                self::RULE_WWW_REQUIRED,
                'www.'.$apex,
                'The standard record set requires a www record.',
            );
        }

        return $violations;
    }

    public function permits(ZoneDesiredState $desired): bool
    {
        return $this->violations($desired) === [];
    }
}
