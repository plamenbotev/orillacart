<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: which page each part of the shop is on; null for none.
 */
final readonly class UpdateShopPages
{
    public function __construct(
        #[Assert\Positive]
        public ?int $shop = null,
        #[Assert\Positive]
        public ?int $cart = null,
        #[Assert\Positive]
        public ?int $checkout = null,
        #[Assert\Positive]
        public ?int $account = null,
    ) {}
}
