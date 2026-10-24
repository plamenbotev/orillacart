<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * A coupon code the customer entered: what it takes off, or why it does not hold.
 */
final readonly class CheckoutCouponView
{
    /**
     * @param Text|null $refused why it does not hold; null when it does
     */
    public function __construct(
        public string $code,
        public string $description,
        public string $amount,
        public bool $freeShipping,
        public ?Text $refused = null,
        /** An automatic discount: shown by its description, and not removed. */
        public bool $automatic = false,
    ) {}
}
