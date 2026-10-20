<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a shipment the shop sent itself arrived, or came back.
 */
final readonly class ShipmentArrived
{
    public function __construct(
        #[Assert\Choice(choices: ['delivered', 'returned'])]
        public string $status = 'delivered',
    ) {}
}
