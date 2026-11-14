<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Catalog\Domain\ProductRepository;

/** The products a tax group taxes. */
final readonly class ProductTaxGroups implements TaxGroupUse
{
    public function __construct(private ProductRepository $products) {}

    public function uses(int $groupId): ?string
    {
        $count = $this->products->countTaxedBy($groupId);

        return $count === 0 ? null : \sprintf($count === 1 ? '%d product' : '%d products', $count);
    }
}
