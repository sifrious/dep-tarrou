<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Plan;

enum OperationKind: string
{
    case CreateRecord = 'record.create';
    case UpdateRecord = 'record.update';
    case DeleteRecord = 'record.delete';
    case SetNameservers = 'zone.nameservers';
    case SetTlsMode = 'zone.tls_mode';

    public function defaultRisk(): Risk
    {
        return match ($this) {
            self::CreateRecord => Risk::Additive,
            self::UpdateRecord => Risk::Replacing,
            self::DeleteRecord => Risk::Destructive,
            self::SetNameservers => Risk::Delegating,
            self::SetTlsMode => Risk::Replacing,
        };
    }
}
