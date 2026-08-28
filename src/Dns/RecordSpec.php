<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Dns;

use InvalidArgumentException;

/**
 * One intended DNS record, provider-neutral.
 *
 * Identity is (type, name) plus, for types that may repeat at a name, the
 * normalized content. Two specs with the same identity are the same record
 * and differ only in their mutable attributes.
 */
final readonly class RecordSpec
{
    public function __construct(
        public RecordType $type,
        public string $name,
        public string $content,
        public int $ttl = 3600,
        public ?int $priority = null,
        public ?bool $proxied = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A record name may not be empty.');
        }

        if ($ttl < 1) {
            throw new InvalidArgumentException("TTL must be positive, got [{$ttl}].");
        }

        if ($type === RecordType::MX && $priority === null) {
            throw new InvalidArgumentException("An MX record for [{$name}] requires a priority.");
        }
    }

    public function normalizedName(): string
    {
        return rtrim(strtolower(trim($this->name)), '.');
    }

    public function normalizedContent(): string
    {
        $content = trim($this->content);

        return $this->type->hasHostnameContent()
            ? rtrim(strtolower($content), '.')
            : $content;
    }

    /**
     * Stable identity for diffing. Repeatable types include their content so
     * that two MX records at the same name are two records, not one changed one.
     */
    public function identity(): string
    {
        return $this->type->isSingular()
            ? $this->type->value.'|'.$this->normalizedName()
            : $this->type->value.'|'.$this->normalizedName().'|'.$this->normalizedContent();
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->normalizedName(),
            'content' => $this->normalizedContent(),
            'ttl' => $this->ttl,
            'priority' => $this->priority,
            'proxied' => $this->proxied,
        ];
    }

    /**
     * Attributes that can change without changing which record this is.
     */
    public function attributesMatch(self $other): bool
    {
        return $this->normalizedContent() === $other->normalizedContent()
            && $this->ttl === $other->ttl
            && $this->priority === $other->priority
            && $this->proxied === $other->proxied;
    }
}
