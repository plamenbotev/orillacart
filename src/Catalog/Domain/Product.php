<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * What the shop sells: the commerce side of a product post, which keeps the name, description and images.
 * The product shares the post's id. It owns its attributes and variations; attribute sets are shared with
 * other products and only referred to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'product_sku', columns: ['sku'])]
class Product
{
    use AttributeOwner;

    #[ORM\Column(length: 20, enumType: ProductType::class)]
    private ProductType $type;

    #[ORM\Column(length: ProductDetails::MAX_SKU_LENGTH, nullable: true)]
    private ?string $sku;

    #[ORM\Embedded(columnPrefix: false)]
    private Pricing $pricing;

    #[ORM\Column]
    private bool $taxable;

    #[ORM\Column(nullable: true)]
    private ?int $taxGroupId;

    #[ORM\Column(length: 20, enumType: StockMode::class)]
    private StockMode $stockMode;

    #[ORM\Column]
    private bool $purchasable;

    #[ORM\Column]
    private bool $featured;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $weight;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $length;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $width;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    private ?string $height;

    #[ORM\Column(length: 10, enumType: PriceVisibility::class, options: ['default' => 'shop'])]
    private PriceVisibility $priceVisibility = PriceVisibility::Shop;

    #[ORM\Column(nullable: true)]
    private ?int $shippingClassId = null;

    #[ORM\Column(length: 10, enumType: Backorders::class, options: ['default' => 'no'])]
    private Backorders $backorders = Backorders::No;

    #[ORM\Column(nullable: true)]
    private ?int $backorderLimit = null;

    #[ORM\Column(nullable: true)]
    private ?int $lowStockThreshold = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(nullable: true)]
    private ?int $downloadLimit = null;

    #[ORM\Column(nullable: true)]
    private ?int $downloadExpiryDays = null;

    /** @var array{items: list<array{product_id: int, variation_ids: list<int>, quantity: int, optional: bool}>, pricing: string, discount: ?string}|null what it is made of, when it is a bundle */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bundle = null;

    /** @var Collection<int, DownloadFile> */
    #[ORM\OneToMany(targetEntity: DownloadFile::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $downloads;

    /** @var Collection<int, Attribute> the product's own attributes */
    #[ORM\OneToMany(targetEntity: Attribute::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attributes;

    /** @var Collection<int, AttributeSet> */
    #[ORM\ManyToMany(targetEntity: AttributeSet::class)]
    #[ORM\JoinTable(name: 'product_attribute_sets')]
    #[ORM\JoinColumn(name: 'product_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'set_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $sets;

    /** @var Collection<int, Variation> */
    #[ORM\OneToMany(targetEntity: Variation::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $variations;

    /**
     * @param int $id the id of the product's WordPress post
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        ProductDetails $details,
    ) {
        $this->attributes = new ArrayCollection();
        $this->sets = new ArrayCollection();
        $this->variations = new ArrayCollection();
        $this->downloads = new ArrayCollection();
        $this->change($details);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function details(): ProductDetails
    {
        return new ProductDetails(
            $this->type,
            $this->pricing,
            $this->sku,
            $this->taxable,
            $this->taxGroupId,
            $this->stockMode,
            $this->purchasable,
            $this->featured,
            $this->weight === null ? null : self::trimDecimal($this->weight),
            $this->length === null ? null : self::trimDecimal($this->length),
            $this->width === null ? null : self::trimDecimal($this->width),
            $this->height === null ? null : self::trimDecimal($this->height),
            $this->downloadLimit,
            $this->downloadExpiryDays,
            $this->priceVisibility,
            $this->shippingClassId,
            $this->backorders,
            $this->backorderLimit,
            $this->lowStockThreshold,
            $this->taxCode,
        );
    }

    public function change(ProductDetails $details): void
    {
        $this->type = $details->type;
        $this->sku = $details->sku;
        $this->pricing = $details->pricing;
        $this->taxable = $details->taxable;
        $this->taxGroupId = $details->taxGroupId;
        $this->stockMode = $details->stockMode;
        $this->purchasable = $details->purchasable;
        $this->featured = $details->featured;
        $this->weight = $details->weight;
        $this->length = $details->length;
        $this->width = $details->width;
        $this->height = $details->height;
        $this->downloadLimit = $details->downloadLimit;
        $this->downloadExpiryDays = $details->downloadExpiryDays;
        $this->priceVisibility = $details->priceVisibility;
        $this->shippingClassId = $details->shippingClassId;
        $this->backorders = $details->backorders;
        $this->backorderLimit = $details->backorderLimit;
        $this->lowStockThreshold = $details->lowStockThreshold;
        $this->taxCode = $details->taxCode;
    }

    /** Its shipping class is gone; it ships as an item without one. */
    public function leaveShippingClass(): void
    {
        $this->shippingClassId = null;
    }

    // ------------------------------------------------------------------------------------------ downloads

    /**
     * @return list<DownloadFile> what a buyer may download, in the order they were added
     */
    public function downloads(): array
    {
        return array_values($this->downloads->toArray());
    }

    public function addDownload(string $name, string $storageKey, string $mimeType, int $size): DownloadFile
    {
        $file = new DownloadFile($this, $name, $storageKey, $mimeType, $size);
        $this->downloads->add($file);

        return $file;
    }

    /**
     * @throws CatalogRuleViolation for a file that is not this product's
     */
    public function download(int $fileId): DownloadFile
    {
        foreach ($this->downloads as $file) {
            if ($file->isSaved() && $file->id() === $fileId) {
                return $file;
            }
        }

        throw new CatalogRuleViolation('File %d is not one of this product\'s downloads.', 'orillacart_unknown_download', values: [$fileId]);
    }

    /**
     * @return DownloadFile the removed file, whose stored copy the caller deletes when no order needs it
     */
    public function removeDownload(int $fileId): DownloadFile
    {
        $file = $this->download($fileId);
        $this->downloads->removeElement($file);

        return $file;
    }

    // ------------------------------------------------------------------------------------------ attributes

    /**
     * @return list<AttributeSet> the sets assigned to the product, active or not
     */
    public function sets(): array
    {
        return array_values($this->sets->toArray());
    }

    /**
     * Every attribute the product offers: those of its active sets, then its own, each in display order.
     *
     * @return list<Attribute>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->sets as $set) {
            if ($set->isActive()) {
                $attributes = [...$attributes, ...$set->ownAttributes()];
            }
        }

        return [...$attributes, ...$this->ownAttributes()];
    }

    /**
     * Replaces the product's own attributes and its attribute sets.
     *
     * @param list<AttributeDraft> $drafts the product's whole list of own attributes, in display order
     * @param list<AttributeSet>   $sets
     *
     * @throws CatalogRuleViolation when a variation uses an option this would remove
     */
    public function replaceAttributes(array $drafts, array $sets): void
    {
        $removed = $this->optionIdsRemovedBy($drafts);
        $keptSets = array_map(static fn(AttributeSet $set): int => $set->id(), $sets);
        foreach ($this->sets as $set) {
            if (!\in_array($set->id(), $keptSets, true)) {
                foreach ($set->ownAttributes() as $attribute) {
                    $removed = [...$removed, ...$attribute->savedOptionIds()];
                }
            }
        }
        $this->refuseIfVariationsUse($removed);

        $this->syncAttributes($drafts, fn(AttributeDraft $draft, int $position): Attribute => new Attribute($this, null, $draft, $position));
        $this->sets->clear();
        foreach ($sets as $set) {
            if (!$this->sets->contains($set)) {
                $this->sets->add($set);
            }
        }
    }

    /**
     * What the customer can choose from, per attribute in display order. A product with variations offers only
     * the options its variations use, and only the attributes that have such options.
     *
     * @return list<array{Attribute, list<AttributeOption>}>
     */
    public function offeredOptions(): array
    {
        $offered = [];
        foreach ($this->attributes() as $attribute) {
            $options = $attribute->options();
            if (!$this->variations->isEmpty()) {
                $options = array_values(array_filter($options, $this->isUsedByAVariation(...)));
            }
            if ($options !== []) {
                $offered[] = [$attribute, $options];
            }
        }

        return $offered;
    }

    // ------------------------------------------------------------------------------------------ variations

    /**
     * @return list<Variation> in the order they were added
     */
    public function variations(): array
    {
        $variations = array_values($this->variations->toArray());
        usort($variations, static fn(Variation $a, Variation $b): int => $a->id() <=> $b->id());

        return $variations;
    }

    public function hasVariations(): bool
    {
        return !$this->variations->isEmpty();
    }

    /**
     * @param int $id the id of the variation's post
     *
     * @throws CatalogRuleViolation for options the product does not have, or a combination another variation has
     */
    public function addVariation(int $id, VariationDetails $details): Variation
    {
        if ($this->bundle !== null) {
            throw new CatalogRuleViolation('A bundle has no variations: its items have theirs.', 'orillacart_invalid_bundle');
        }
        $options = $this->variationOptions($details, null);
        $variation = new Variation($id, $this, $details, $options);
        $this->variations->add($variation);

        return $variation;
    }

    /**
     * @throws VariationNotFound
     * @throws CatalogRuleViolation for options the product does not have, or a combination another variation has
     */
    public function changeVariation(int $variationId, VariationDetails $details): Variation
    {
        $variation = $this->variation($variationId);
        $variation->change($details, $this->variationOptions($details, $variation));

        return $variation;
    }

    /**
     * @throws VariationNotFound
     */
    public function removeVariation(int $variationId): void
    {
        $this->variations->removeElement($this->variation($variationId));
    }

    /**
     * @throws VariationNotFound
     */
    public function variation(int $variationId): Variation
    {
        return $this->variations->findFirst(static fn(int $key, Variation $variation): bool => $variation->id() === $variationId)
            ?? throw new VariationNotFound($variationId);
    }

    // ------------------------------------------------------------------------------------------- selling

    /**
     * Checks the customer's choice of options and prices it.
     *
     * A product with variations is sold only as one of them: the options must be exactly a variation's, and the
     * variation's own price applies. Otherwise each chosen option must be one the product offers, at most one per
     * attribute, every required attribute needs one, and the options' adjustments are added to the product price,
     * which never goes below zero.
     *
     * @param list<int> $optionIds
     *
     * @throws InvalidSelection
     */
    public function configure(array $optionIds, \DateTimeImmutable $now): Configuration
    {
        $optionIds = array_values(array_unique($optionIds));
        $chosen = [];
        $offered = $this->offeredOptions();
        foreach ($optionIds as $optionId) {
            $found = false;
            foreach ($offered as [$attribute, $options]) {
                foreach ($options as $option) {
                    if ($option->isSaved() && $option->id() === $optionId) {
                        if (isset($chosen[spl_object_id($attribute)])) {
                            throw new InvalidSelection('Choose one option for "%s".', values: [$attribute->name()]);
                        }
                        $chosen[spl_object_id($attribute)] = $option;
                        $found = true;
                    }
                }
            }
            if (!$found) {
                throw new InvalidSelection('Option %d is not available for this product.', values: [$optionId]);
            }
        }

        if ($this->hasVariations()) {
            sort($optionIds);
            foreach ($this->variations as $variation) {
                if ($variation->signature() === $optionIds) {
                    return new Configuration($this, $variation, [], $variation->pricing()->priceAt($now));
                }
            }
            throw new InvalidSelection('The product is not sold with this combination of options.');
        }

        $base = $this->pricing->priceAt($now);
        $price = $base;
        foreach ($offered as [$attribute, $options]) {
            $option = $chosen[spl_object_id($attribute)] ?? null;
            if ($option === null && $attribute->isRequired()) {
                throw new InvalidSelection('Choose an option for "%s".', values: [$attribute->name()]);
            }
            if ($option !== null) {
                $price = $price->add($option->adjustmentOn($base));
            }
        }

        return new Configuration($this, null, array_values($chosen), $price->atLeastZero());
    }

    /** What it is made of, when it is a bundle. */
    public function bundle(): ?Bundle
    {
        return $this->bundle === null ? null : Bundle::fromArray($this->bundle);
    }

    public function isBundle(): bool
    {
        return $this->bundle !== null;
    }

    /**
     * Makes the product a bundle of other products, or a product of its own again.
     *
     * @throws CatalogRuleViolation for a product with variations, or one that holds itself
     */
    public function makeBundle(?Bundle $bundle): void
    {
        if ($bundle !== null && !$this->variations->isEmpty()) {
            throw new CatalogRuleViolation('A product with variations cannot be a bundle.', 'orillacart_invalid_bundle');
        }
        foreach ($bundle->items ?? [] as $item) {
            if ($item->productId === $this->id) {
                throw new CatalogRuleViolation('A bundle cannot hold itself.', 'orillacart_invalid_bundle');
            }
        }
        $this->bundle = $bundle?->toArray();
    }

    public function priceAt(\DateTimeImmutable $now): Money
    {
        return $this->pricing->priceAt($now);
    }

    /**
     * Ids of every option of the product's own attributes, which go with the product when it is deleted.
     *
     * @return list<int>
     */
    public function ownOptionIds(): array
    {
        $ids = [];
        foreach ($this->attributes as $attribute) {
            $ids = [...$ids, ...$attribute->savedOptionIds()];
        }

        return $ids;
    }

    /**
     * @return list<AttributeOption>
     */
    private function variationOptions(VariationDetails $details, ?Variation $current): array
    {
        $options = [];
        $attributes = [];
        foreach ($details->optionIds as $optionId) {
            $option = $this->findOption($optionId)
                ?? throw new CatalogRuleViolation('Option %d is not one of this product\'s options.', 'orillacart_unknown_option', values: [$optionId]);
            if (isset($attributes[spl_object_id($option->attribute())])) {
                throw new CatalogRuleViolation('A variation has one option for "%s".', 'orillacart_invalid_variation', values: [$option->attribute()->name()]);
            }
            $attributes[spl_object_id($option->attribute())] = true;
            $options[] = $option;
        }

        $signature = array_values(array_unique($details->optionIds));
        sort($signature);
        foreach ($this->variations as $other) {
            if ($other !== $current && $other->signature() === $signature) {
                throw new CatalogRuleViolation('Another variation already has these options.', 'orillacart_duplicate_variation');
            }
        }

        return $options;
    }

    private function findOption(int $optionId): ?AttributeOption
    {
        foreach ($this->attributes() as $attribute) {
            foreach ($attribute->options() as $option) {
                if ($option->isSaved() && $option->id() === $optionId) {
                    return $option;
                }
            }
        }

        return null;
    }

    private function isUsedByAVariation(AttributeOption $option): bool
    {
        return $this->variations->exists(static fn(int $key, Variation $variation): bool => $variation->uses($option));
    }

    /**
     * @param list<int> $optionIds
     */
    private function refuseIfVariationsUse(array $optionIds): void
    {
        foreach ($this->variations as $variation) {
            if (array_intersect($variation->signature(), $optionIds) !== []) {
                throw new CatalogRuleViolation('A variation uses an option this would remove; change or remove the variation first.', 'orillacart_option_in_use');
            }
        }
    }

    private static function trimDecimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** The shop measures in other units now: its weight and sides in them, and its variations'. */
    public function measuredIn(float $weightFactor, float $lengthFactor): void
    {
        $this->weight = self::scaled($this->weight, $weightFactor);
        $this->length = self::scaled($this->length, $lengthFactor);
        $this->width = self::scaled($this->width, $lengthFactor);
        $this->height = self::scaled($this->height, $lengthFactor);
        foreach ($this->variations as $variation) {
            $variation->measuredIn($weightFactor, $lengthFactor);
        }
    }

    /** A measure in other units, to four decimals; none stays none. */
    private static function scaled(?string $value, float $factor): ?string
    {
        return $value === null ? null : number_format((float) $value * $factor, 4, '.', '');
    }
}
