<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A placed order: where to send the customer next. With a redirect address the customer pays there first;
 * otherwise the receipt is next.
 */
final readonly class PlacedOrderView
{
    public function __construct(
        public int $number,
        public string $key,
        public string $status,
        public string $total,
        public string $receiptUrl,
        public ?string $redirectUrl,
    ) {}
}
