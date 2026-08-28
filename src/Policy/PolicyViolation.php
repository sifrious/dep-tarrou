<?php

declare(strict_types=1);

namespace Sifrious\Tarrou\Policy;

final readonly class PolicyViolation
{
    public function __construct(
        public string $rule,
        public string $subject,
        public string $detail,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'subject' => $this->subject,
            'detail' => $this->detail,
        ];
    }
}
