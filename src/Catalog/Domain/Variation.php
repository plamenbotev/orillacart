<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One combination of a product's options that is sold as its own item, such as the hoodie in size M,
 * with its own price, SKU and stock.
 */
#[ORM\Entity]
#[ORM\Table(name: 'variations')]
#[ORM\UniqueConstraint(name: 'variation_sku', columns: ['sku'])]
class Variation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: ProductDetails::MAX_SKU_LENGTH, nullable: true)]
    private ?string $sku;

    #[ORM\Embedded(columnPrefix: false)]
    private Pricing $pricing;

    #[ORM\Column(length: 20, enumType: StockMode::class)]
    private StockMode $stockMode;

    /** @var Collection<int, AttributeOption> */
    #[ORM\ManyToMany(targetEntity: AttributeOption::class)]
    #[ORM\JoinTable(name: 'variation_options')]
    #[ORM\JoinColumn(name: 'variation_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'option_id', onDelete: 'CASCADE')]
    private Collection $options;

    /**
     * Created through Product::addVariation().
     *
     * @param list<AttributeOption> $options
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variations')]
        #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        VariationDetails $details,
        array $options,
    ) {
        $this->options = new ArrayCollection();
        $this->change($details, $options);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The variation has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function pricing(): Pricing
    {
        return $this->pricing;
    }

    public function stockMode(): StockMode
    {
        return $this->stockMode;
    }

    /**
     * @return list<AttributeOption> in the order of their attributes
     */
    public function options(): array
    {
        $options = array_values($this->options->toArray());
        usort($options, static fn(AttributeOption $a, AttributeOption $b): int => $a->attribute()->position() <=> $b->attribute()->position());

        return $options;
    }

    /**
     * The variation's options as a sorted list of ids; two variations of a product never share one.
     *
     * @return list<int>
     */
    public function signature(): array
    {
        $ids = array_map(static fn(AttributeOption $option): int => $option->id(), $this->options->toArray());
        sort($ids);

        return $ids;
    }

    public function uses(AttributeOption $option): bool
    {
        return $this->options->contains($option);
    }

    /**
     * @internal for Product, which checks the options
     *
     * @param list<AttributeOption> $options
     */
    public function change(VariationDetails $details, array $options): void
    {
        $this->sku = $details->sku;
        $this->pricing = $details->pricing;
        $this->stockMode = $details->stockMode;
        $this->options->clear();
        foreach ($options as $option) {
            $this->options->add($option);
        }
    }
}
