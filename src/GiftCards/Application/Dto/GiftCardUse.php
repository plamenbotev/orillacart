<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/**
 * A gift card, or the customer's store credit, paying part of an order: what it has and what it pays. The code is
 * as the customer entered it; null for store credit.
 */
final readonly class GiftCardUse
{
    public function __construct(
        public int $cardId,
        public ?string $code,
        public string $label,
        public string $balance,
        public string $amount,
    ) {}
}
