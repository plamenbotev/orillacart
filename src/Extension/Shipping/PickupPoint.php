<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Shipping;

/**
 * An office or locker of the courier.
 */
final readonly class PickupPoint
{
    /**
     * @param string $id      the courier's id, stable: the shipment names the point by it; up to 64 characters
     * @param string $address one line: "12 Vitosha Blvd, 1000 Sofia"
     * @param string $hours   when it is open, as the courier says; empty when it does not
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $address,
        public string $hours = '',
    ) {
        if ($id === '' || mb_strlen($id) > 64) {
            throw new \InvalidArgumentException('A pickup point needs an id of up to 64 characters.');
        }
    }
}
