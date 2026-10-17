<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

/**
 * What a stockroom is called and how long a delivery from it takes.
 */
final readonly class StockroomDetails
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_DELIVERY_DAYS = 365;

    /**
     * @param bool $active an inactive stockroom keeps its stock, but none of it is sold or taken
     */
    public function __construct(
        public string $name,
        public int $minDeliveryDays = 0,
        public int $maxDeliveryDays = 0,
        public bool $active = true,
        public string $description = '',
    ) {
        if (trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InventoryRuleViolation(\sprintf('A stockroom needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_stockroom_name');
        }
        if ($minDeliveryDays < 0 || $maxDeliveryDays > self::MAX_DELIVERY_DAYS || $minDeliveryDays > $maxDeliveryDays) {
            throw new InventoryRuleViolation(
                \sprintf('Delivery takes from 0 to %d days, and the shortest time cannot be longer than the longest.', self::MAX_DELIVERY_DAYS),
                'orillacart_invalid_delivery_time',
            );
        }
    }
}
