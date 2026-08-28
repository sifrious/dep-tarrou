<?php

declare(strict_types=1);

use Sifrious\Tarrou\Dns\RecordSpec;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function record(
    string $type,
    string $name,
    string $content,
    int $ttl = 3600,
    ?int $priority = null,
    ?bool $proxied = null,
): RecordSpec {
    return new RecordSpec(RecordType::parse($type), $name, $content, $ttl, $priority, $proxied);
}
