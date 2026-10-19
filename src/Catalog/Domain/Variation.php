<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One combination of a product's options that is sold as its own item, such as the hoodie in size M,
 * with its own price, SKU, stock, and weight and size where they differ from the product's. Like a product, it
 * shares its id with a WordPress post (a child of the product's) that holds its name, description and images.
 */
#[ORM\Entity]
#[ORM\Table(name: 'variations')]
#[ORM\UniqueConstraint(name: 'variation_sku', columns: ['sku'])]
class Variation
{
    #[ORM\Column(length: ProductDetails::MAX_SKU_LENGTH, nullable: true)]
    private ?string $sku;


    #[ORM\Embedded(columnPrefix: false)]
    private Pricing $pricing;

    #[ORM\Column(length: 20, enumType: StockMode::class)]
    private StockMode $stockMode;

    /** Null when it follows its product. */
    #[ORM\Column(length: 10, nullable: true, enumType: Backorders::class)]
    private ?Backorders $backorders = null;

    /** How many it may owe at most; null when it follows its product. */
    #[ORM\Column(nullable: true)]
    private ?int $backorderLimit = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $weight = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $length = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $width = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $height = null;

    /** @var Collection<int, AttributeOption> */
    #[ORM\ManyToMany(targetEntity: AttributeOption::class)]
    #[ORM\JoinTable(name: 'variation_options')]
    #[ORM\JoinColumn(name: 'variation_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'option_id', onDelete: 'CASCADE')]
    private Collection $options;

    /**
     * Created through Product::addVariation().
     *
     * @param int                   $id      the id of the variation's post
     * @param list<AttributeOption> $options
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
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
        return $this->id;
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

    /** Its own setting; null when it follows its product. */
    public function backorders(): ?Backorders
    {
        return $this->backorders;
    }

    /** Its own limit; null when it follows its product. */
    public function backorderLimit(): ?int
    {
        return $this->backorderLimit;
    }

    /** Its own weight, or null for the product's. */
    public function weight(): ?string
    {
        return self::trimmed($this->weight);
    }

    public function length(): ?string
    {
        return self::trimmed($this->length);
    }

    public function width(): ?string
    {
        return self::trimmed($this->width);
    }

    public function height(): ?string
    {
        return self::trimmed($this->height);
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
        $this->backorders = $details->backorders;
        $this->backorderLimit = $details->backorderLimit;
        $this->weight = $details->weight;
        $this->length = $details->length;
        $this->width = $details->width;
        $this->height = $details->height;
        $this->options->clear();
        foreach ($options as $option) {
            $this->options->add($option);
        }
    }

    private static function trimmed(?string $value): ?string
    {
        return $value === null || !str_contains($value, '.') ? $value : rtrim(rtrim($value, '0'), '.');
    }

    /** The shop measures in other units now: its weight and sides in them. */
    public function measuredIn(float $weightFactor, float $lengthFactor): void
    {
        $this->weight = self::scaled($this->weight, $weightFactor);
        $this->length = self::scaled($this->length, $lengthFactor);
        $this->width = self::scaled($this->width, $lengthFactor);
        $this->height = self::scaled($this->height, $lengthFactor);
    }

    /** A measure in other units, to four decimals; none stays none. */
    private static function scaled(?string $value, float $factor): ?string
    {
        return $value === null ? null : number_format((float) $value * $factor, 4, '.', '');
    }
}
