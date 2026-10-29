<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something an indexed product can be filtered by: a category, a brand, a SKU or an option value. A value of
 * one variant holds only for it; one without a variant holds for the whole product.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_values')]
#[ORM\Index(name: 'search_value_facet', columns: ['facet', 'value', 'product_id'])]
#[ORM\Index(name: 'search_value_product', columns: ['product_id', 'facet'])]
class IndexedValue
{
    public const int MAX_FACET_LENGTH = 100;
    public const int MAX_VALUE_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private int $productId,
        #[ORM\Column(nullable: true)]
        private ?int $variantId,
        #[ORM\Column(length: self::MAX_FACET_LENGTH)]
        private string $facet,
        #[ORM\Column(length: self::MAX_VALUE_LENGTH)]
        private string $value,
        #[ORM\Column(length: 255)]
        private string $label,
        /** @var array<string, string>|null the label in the site's other languages, by locale, where it differs */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $labels = null,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function variantId(): ?int
    {
        return $this->variantId;
    }

    public function facet(): string
    {
        return $this->facet;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }
}
