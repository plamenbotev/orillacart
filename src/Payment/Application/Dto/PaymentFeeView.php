<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * The fee an order pays for a way to pay: a fixed amount and a percentage of the items, less discounts, and
 * shipping, as the shop's prices are.
 */
final readonly class PaymentFeeView
{
    public function __construct(
        public string $label,
        public string $fixed,
        public string $percent,
    ) {}
}
