<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A fee the order pays besides its items and shipping, such as for its way to pay: before tax, or including it
 * when the order's prices do.
 */
final readonly class OrderFee
{
    public function __construct(
        public string $label,
        public Money $amount,
    ) {}
}
