<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Application\Dto;

use OrillaCart\Inventory\Domain\StockroomDetails;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: create a stockroom, or change one.
 */
final readonly class SaveStockroom
{
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
    ) {}
}
