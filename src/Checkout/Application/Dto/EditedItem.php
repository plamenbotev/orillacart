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
     * @param list<int> $bundleChoices for a bundle, the variations chosen and the optional products added
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
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Positive()])]
        public array $bundleChoices = [],
        /** The order's line it continues, by its place from 0: it keeps that line's answers to the product's forms. */
        #[Assert\PositiveOrZero]
        public ?int $line = null,
    ) {}
}
