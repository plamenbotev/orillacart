<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * A SKU and the product or variation that has it: one row a SKU, so the database itself refuses a second
 * product or variation with it, also from two saves at once. Kept by the product repository with each save.
 */
#[ORM\Entity]
#[ORM\Table(name: 'skus')]
class ClaimedSku
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: ProductDetails::MAX_SKU_LENGTH)]
        private string $sku,
        #[ORM\Column]
        #[References('products', 'CASCADE')]
        private int $productId,
        #[ORM\Column(nullable: true)]
        #[References('variations', 'CASCADE')]
        private ?int $variationId,
    ) {}

    public function sku(): string
    {
        return $this->sku;
    }
}
