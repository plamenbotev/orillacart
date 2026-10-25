<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain\Event;

/**
 * A consumer withdrew from a purchase through the shop's withdrawal function: they get an acknowledgement at once,
 * with what they declared and when.
 */
final readonly class WithdrawalDeclared
{
    /**
     * @param list<array{name: string, quantity: int}> $items
     */
    public function __construct(
        public int $orderNumber,
        public int $returnId,
        public string $name,
        public string $confirmationEmail,
        public string $orderEmail,
        public array $items,
        /** When it was sent, ISO 8601: the withdrawal is in time if this is within the period. */
        public string $declaredAt,
        public ?string $locale,
    ) {}
}
