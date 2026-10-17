<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use OrillaCart\Catalog\Application\Dto\SaveProduct;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An item of an order as the shop edits it. Without a unit price, the catalog's current price applies.
 */
final readonly class EditedItem
{
    /**
     * @param list<int> $optionIds
     */
    public function __construct(
        #[Assert\Positive]
        public int $productId,
        #[Assert\Range(min: 1, max: 9999)]
        public int $quantity,
        #[Assert\All([new Assert\Positive()])]
        public array $optionIds = [],
        #[Assert\Regex(SaveProduct::AMOUNT, message: SaveProduct::AMOUNT_MESSAGE)]
        public ?string $unitPrice = null,
    ) {}
}
