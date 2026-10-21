<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/**
 * A gift card just made, or given a new code: the code shows this once, as the shop keeps only its hash.
 */
final readonly class IssuedGiftCardView
{
    public function __construct(
        public string $code,
        public GiftCardView $card,
        /** Whether the email with the code went out. */
        public bool $sent,
    ) {}
}
