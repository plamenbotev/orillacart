<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Application\Wishlists;
use Symfony\Component\Validator\Constraints as Assert;

/** The wishlist a visitor kept in their browser, joining their account's when they log in. */
final readonly class MergeWishlist
{
    /**
     * @param list<int> $productIds newest first
     */
    public function __construct(
        #[Assert\Count(max: Wishlists::MOST)]
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $productIds = [],
    ) {}
}
