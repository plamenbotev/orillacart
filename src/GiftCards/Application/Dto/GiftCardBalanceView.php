<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/**
 * What a gift card has left, as its holder may ask.
 */
final readonly class GiftCardBalanceView
{
    public function __construct(
        public string $label,
        public string $balance,
        public string $currency,
        public ?string $expiresAt,
        /** Why it cannot pay now; null when it can. */
        public ?string $refused,
    ) {}
}
