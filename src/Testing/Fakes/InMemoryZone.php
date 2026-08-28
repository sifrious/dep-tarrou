<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Testing\Fakes;

use DateTimeImmutable;
use RuntimeException;
use Sifrious\Tarrou\Apply\OperationOutcome;
use Sifrious\Tarrou\Contracts\MutationCapability;
use Sifrious\Tarrou\Contracts\ObservedStateReader;
use Sifrious\Tarrou\Dns\RecordSpec;
use Sifrious\Tarrou\Dns\RecordType;
use Sifrious\Tarrou\Dns\TlsMode;
use Sifrious\Tarrou\Dns\ZoneObservedState;
use Sifrious\Tarrou\Plan\Operation;
use Sifrious\Tarrou\Plan\OperationKind;

/**
 * A provider that exists only in memory.
 *
 * It is the reference implementation of the idempotency requirement: applying
 * an operation whose effect is already present reports AlreadyConverged, and
 * a plan applied twice changes nothing the second time.
 */
final class InMemoryZone implements MutationCapability, ObservedStateReader
{
    /** @var array<string, RecordSpec> */
    private array $records = [];

    /** @var list<string> */
    private array $nameservers = [];

    private ?TlsMode $tlsMode = null;

    /** @var list<OperationKind> */
    private array $unsupported = [];

    /** @var list<string> */
    private array $failing = [];

    /** @var list<string> */
    private array $losingAcknowledgement = [];

    private bool $complete = true;

    /**
     * @param  list<RecordSpec>  $records
     * @param  list<string>  $nameservers
     */
    public function __construct(
        private readonly string $domain,
        array $records = [],
        array $nameservers = [],
        ?TlsMode $tlsMode = null,
    ) {
        foreach ($records as $record) {
            $this->records[$record->identity()] = $record;
        }

        $this->nameservers = array_values($nameservers);
        $this->tlsMode = $tlsMode;
    }

    public function withoutSupportFor(OperationKind ...$kinds): self
    {
        $this->unsupported = array_values($kinds);

        return $this;
    }

    public function failingOn(string ...$targets): self
    {
        $this->failing = array_values($targets);

        return $this;
    }

    /**
     * The provider carries the change out and the acknowledgement is lost on
     * the way back. This is the case a retry has to survive without creating
     * the record twice.
     */
    public function losingAcknowledgementOn(string ...$targets): self
    {
        $this->losingAcknowledgement = array_values($targets);

        return $this;
    }

    public function incomplete(): self
    {
        $this->complete = false;

        return $this;
    }

    public function observe(string $domain): ZoneObservedState
    {
        return new ZoneObservedState(
            domain: $domain,
            records: array_values($this->records),
            nameservers: $this->nameservers,
            tlsMode: $this->tlsMode,
            observedAt: new DateTimeImmutable,
            complete: $this->complete,
        );
    }

    /**
     * @return list<OperationKind>
     */
    public function supportedOperations(): array
    {
        return array_values(array_filter(
            OperationKind::cases(),
            fn (OperationKind $kind): bool => ! in_array($kind, $this->unsupported, true),
        ));
    }

    public function supports(OperationKind $kind): bool
    {
        return ! in_array($kind, $this->unsupported, true);
    }

    public function apply(Operation $operation): OperationOutcome
    {
        if (in_array($operation->target, $this->failing, true)) {
            throw new RuntimeException("The provider rejected [{$operation->target}].");
        }

        if (in_array($operation->target, $this->losingAcknowledgement, true)) {
            $this->losingAcknowledgement = array_values(array_diff($this->losingAcknowledgement, [$operation->target]));

            $this->carryOut($operation);

            throw new RuntimeException('The connection dropped before the provider acknowledged the change.');
        }

        return $this->carryOut($operation);
    }

    private function carryOut(Operation $operation): OperationOutcome
    {
        return match ($operation->kind) {
            OperationKind::CreateRecord, OperationKind::UpdateRecord => $this->write($operation),
            OperationKind::DeleteRecord => $this->delete($operation),
            OperationKind::SetNameservers => $this->setNameservers($operation),
            OperationKind::SetTlsMode => $this->setTlsMode($operation),
        };
    }

    private function write(Operation $operation): OperationOutcome
    {
        $desired = $this->specFrom((array) $operation->after);
        $existing = $this->records[$desired->identity()] ?? null;

        if ($existing !== null && $existing->attributesMatch($desired)) {
            return OperationOutcome::alreadyConverged($operation);
        }

        $this->records[$desired->identity()] = $desired;

        return OperationOutcome::applied($operation, $existing?->toArray());
    }

    private function delete(Operation $operation): OperationOutcome
    {
        if (! array_key_exists($operation->target, $this->records)) {
            return OperationOutcome::alreadyConverged($operation);
        }

        $prior = $this->records[$operation->target]->toArray();
        unset($this->records[$operation->target]);

        return OperationOutcome::applied($operation, $prior);
    }

    private function setNameservers(Operation $operation): OperationOutcome
    {
        /** @var list<string> $wanted */
        $wanted = (array) (($operation->after['nameservers'] ?? []));
        $current = $this->nameservers;
        sort($wanted);
        sort($current);

        if ($wanted === $current) {
            return OperationOutcome::alreadyConverged($operation);
        }

        $prior = ['nameservers' => $this->nameservers];
        $this->nameservers = $wanted;

        return OperationOutcome::applied($operation, $prior);
    }

    private function setTlsMode(Operation $operation): OperationOutcome
    {
        $wanted = TlsMode::from((string) ($operation->after['tls_mode'] ?? TlsMode::Off->value));

        if ($this->tlsMode === $wanted) {
            return OperationOutcome::alreadyConverged($operation);
        }

        $prior = ['tls_mode' => $this->tlsMode?->value];
        $this->tlsMode = $wanted;

        return OperationOutcome::applied($operation, $prior);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function specFrom(array $attributes): RecordSpec
    {
        return new RecordSpec(
            type: RecordType::parse((string) $attributes['type']),
            name: (string) $attributes['name'],
            content: (string) $attributes['content'],
            ttl: (int) $attributes['ttl'],
            priority: isset($attributes['priority']) ? (int) $attributes['priority'] : null,
            proxied: array_key_exists('proxied', $attributes) && $attributes['proxied'] !== null
                ? (bool) $attributes['proxied']
                : null,
        );
    }
}
