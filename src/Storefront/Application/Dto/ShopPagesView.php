<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use OrillaCart\Storefront\Domain\ShopPages;

final readonly class ShopPagesView
{
    public function __construct(
        public ?int $shop,
        public ?int $cart,
        public ?int $checkout,
        public ?int $account,
    ) {}

    public static function fromPages(ShopPages $pages): self
    {
        return new self($pages->shop, $pages->cart, $pages->checkout, $pages->account);
    }
}
