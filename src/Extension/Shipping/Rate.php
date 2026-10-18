<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * A service the courier offers for a shipment, priced.
 */
final readonly class Rate
{
    /**
     * @param string   $code         the courier's service, stable between quotes: lowercase letters, digits and underscores, up to 40
     * @param string   $name         what checkout shows after the carrier's name: "Express"
     * @param string   $price        before tax, a decimal number: "6.90"
     * @param int|null $deliveryDays  working days to deliver, when the courier says
     * @param bool     $toPickupPoint the service delivers to an office or locker the customer chooses (OffersPickupPoints)
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $price,
        public ?int $deliveryDays = null,
        public bool $toPickupPoint = false,
    ) {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $code) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a service code: lowercase letters, digits and underscores, up to 40.', $code));
        }
        if (preg_match('/^\d{1,9}(\.\d{1,6})?$/', $price) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a price.', $price));
        }
    }
}
