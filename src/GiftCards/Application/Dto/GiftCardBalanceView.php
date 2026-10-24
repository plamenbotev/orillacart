<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * What a gift card has left, as its holder may ask.
 */
final readonly class GiftCardBalanceView
{
    public function __construct(
        public Text $label,
        public string $balance,
        public string $currency,
        public ?string $expiresAt,
        /** Why it cannot pay now; null when it can. */
        public ?Text $refused,
    ) {}
}
