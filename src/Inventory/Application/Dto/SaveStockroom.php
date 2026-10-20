<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\DeliveryUnit;
use OrillaCart\Inventory\Domain\StockroomDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a stockroom, or change one.
 */
final readonly class SaveStockroom
{
    /**
     * @param string $deliveryUnit how the times are spoken of (days or weeks); they are always given in days
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: StockroomDetails::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Range(min: 0, max: StockroomDetails::MAX_DELIVERY_DAYS)]
        public int $minDeliveryDays = 0,
        #[Assert\Range(min: 0, max: StockroomDetails::MAX_DELIVERY_DAYS)]
        public int $maxDeliveryDays = 0,
        public bool $active = true,
        #[Assert\Length(max: 2000)]
        public string $description = '',
        #[Assert\Choice(callback: [DeliveryUnit::class, 'values'])]
        public string $deliveryUnit = 'days',
        /** The shop hears when an item here is down to this many or fewer; null for the shop's. */
        #[Assert\PositiveOrZero]
        public ?int $lowStockThreshold = null,
    ) {}
}
