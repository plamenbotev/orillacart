<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Shipping\Application\Dto\PickupPointView;

/**
 * A way to ship the order, with its price before tax, its tax and both together, rounded to the currency.
 */
final readonly class ShippingChoiceView
{
    public function __construct(
        public string $id,
        public string $carrier,
        public string $name,
        public string $price,
        public string $tax,
        public string $total,
        /** The service delivers to a pickup point the customer chooses. */
        public bool $toPickupPoint = false,
        /** The shop's place where the customer collects the order, for a pickup. */
        public ?PickupPointView $collectAt = null,
    ) {}
}
