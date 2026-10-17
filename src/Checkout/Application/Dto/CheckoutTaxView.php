<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

final readonly class CheckoutTaxView
{
    public function __construct(
        public string $name,
        public string $percent,
        public string $items,
        public string $shipping,
        public string $total,
    ) {}
}
