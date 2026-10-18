<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One way an indexed product is sold: a variation, or the product itself (under its own id) when it has none.
 * Price and stock filters hold for a variant, so "M, red, in stock" finds only a product with such a variation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_variants')]
#[ORM\Index(name: 'search_variant_product', columns: ['product_id'])]
class IndexedVariant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $variantId,
        #[ORM\Column]
        private int $productId,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
        private ?string $price,
        #[ORM\Column]
        private bool $inStock,
    ) {}

    public function variantId(): int
    {
        return $this->variantId;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function price(): ?string
    {
        return $this->price;
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }
}
