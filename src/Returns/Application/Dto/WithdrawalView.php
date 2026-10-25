<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * The withdrawal as declared, which its confirmation repeats.
 */
final readonly class WithdrawalView
{
    /**
     * @param list<array{name: string, quantity: int}> $items
     */
    public function __construct(
        public int $number,
        public string $name,
        public string $confirmationEmail,
        public array $items,
        public string $declaredAt,
    ) {}
}
