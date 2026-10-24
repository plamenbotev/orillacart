<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * A gift card entered at checkout, or the customer's store credit: what it pays of the order, or why it cannot.
 */
final readonly class CheckoutGiftCardView
{
    public function __construct(
        /** As the customer entered it; null for store credit. */
        public ?string $code,
        /** "Gift card ····7KQ2", or "Store credit". */
        public Text|string $label,
        public string $amount,
        /** What it has left before this order. */
        public string $balance = '0',
        public ?Text $refused = null,
    ) {}
}
