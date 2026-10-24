<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveBundle;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\BundleItem;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\Event\DownloadRemoved;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Catalog\Domain\PriceVisibility;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductDetails;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Catalog\Domain\Variation;
use OrillaCart\Catalog\Domain\VariationDetails;
use OrillaCart\Catalog\Domain\VariationNotFound;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shipping\Application\ShippingClassService;
use OrillaCart\Tax\Application\TaxGroupService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for a product's commerce details, as the product editor runs them.
 */
final readonly class ProductService
{
    public function __construct(
        private ProductRepository $products,
        private AttributeSetRepository $sets,
        private ProductContent $content,
        private TaxGroupService $taxGroups,
        private ShippingClassService $shippingClasses,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws ProductNotFound when there is no product post with this id
     */
    public function product(int $id): ProductView
    {
        $name = $this->name($id);
        $product = $this->products->find($id);

        return $product === null ? ProductView::unsaved($id, $name) : ProductView::fromProduct($product, $name, $this->itemName(...));
    }

    /**
     * Creates or replaces the product's commerce details, own attributes and attribute sets.
     *
     * @throws ProductNotFound     when there is no product post with this id
     * @throws CatalogRuleViolation
     */
    public function save(int $id, SaveProduct $command): ProductView
    {
        $name = $this->name($id);
        if ($command->sku !== null && $this->products->isSkuTaken($command->sku, exceptProductId: $id)) {
            throw new CatalogRuleViolation('Another product or variation has the SKU "%s".', 'orillacart_duplicate_sku', values: [$command->sku]);
        }
        if ($command->taxGroupId !== null && !$this->taxGroups->exists($command->taxGroupId)) {
            throw new CatalogRuleViolation('There is no tax group %d.', 'orillacart_unknown_tax_group', values: [$command->taxGroupId]);
        }
        if ($command->shippingClassId !== null && !$this->shippingClasses->exists($command->shippingClassId)) {
            throw new CatalogRuleViolation('There is no shipping class %d.', 'orillacart_unknown_shipping_class', values: [$command->shippingClassId]);
        }
        $sets = array_map(
            fn(int $setId): AttributeSet => $this->sets->find($setId)
                ?? throw new CatalogRuleViolation('There is no attribute set %d.', 'orillacart_unknown_attribute_set', values: [$setId]),
            array_values(array_unique($command->setIds)),
        );

        $details = new ProductDetails(
            ProductType::from($command->type),
            CatalogDrafts::pricing($command->price, $command->sale),
            $command->sku,
            $command->taxable,
            $command->taxGroupId,
            StockMode::from($command->stockMode),
            $command->purchasable,
            $command->featured,
            $command->weight,
            $command->length,
            $command->width,
            $command->height,
            $command->downloadLimit,
            $command->downloadExpiryDays,
            PriceVisibility::from($command->priceVisibility),
            $command->shippingClassId,
            Backorders::from($command->backorders),
            $command->backorderLimit,
            $command->lowStockThreshold,
            $command->taxCode === null || trim($command->taxCode) === '' ? null : trim($command->taxCode),
        );
        $product = $this->products->find($id);
        if ($product === null) {
            $product = new Product($id, $details);
        } else {
            $product->change($details);
        }
        $drafts = CatalogDrafts::attributes($command->attributes);
        $removedOptions = $product->optionIdsRemovedBy($drafts);
        $product->replaceAttributes($drafts, $sets);
        $product->makeBundle($command->bundle === null ? null : $this->bundle($command->bundle));
        $this->products->save($product);

        $this->events->dispatch(new ProductChanged($id));
        if ($removedOptions !== []) {
            $this->events->dispatch(new CatalogItemsRemoved(optionIds: $removedOptions));
        }

        return ProductView::fromProduct($product, $name, $this->itemName(...));
    }

    /**
     * The regular price the product list's quick edit changes; null when the product's own price is not what it
     * sells for: its variations have theirs, and a bundle priced by its items adds theirs up.
     */
    public function ownPrice(int $id): ?string
    {
        $product = $this->products->find($id);

        return $product === null || !self::pricedByItself($product) ? null : (string) $product->details()->pricing->price();
    }

    /**
     * Changes the regular price only, keeping a sale, as the product list's quick edit does.
     *
     * @throws ProductNotFound      also when the product's commerce details were never saved
     * @throws CatalogRuleViolation for a negative price, or a product whose own price it does not sell for
     */
    public function changePrice(int $id, string $price): void
    {
        $product = $this->get($id);
        if (!self::pricedByItself($product)) {
            throw new CatalogRuleViolation('Its variations, or the items of the bundle, have the prices: change them in the product\'s editor.', 'orillacart_price_not_its_own');
        }
        $details = $product->details();
        $product->change($details->withPrice(new Pricing(Money::of($price), $details->pricing->sale())));
        $this->products->save($product);
        $this->events->dispatch(new ProductChanged($id));
    }

    /**
     * Forgets the product's commerce details, attributes and variations; its post is being deleted.
     */
    public function delete(int $id): void
    {
        $product = $this->products->find($id);
        if ($product === null) {
            return;
        }
        $removed = new CatalogItemsRemoved(
            [$id],
            array_map(static fn(Variation $variation): int => $variation->id(), $product->variations()),
            $product->ownOptionIds(),
        );
        $files = $product->downloads();
        $variationIds = array_map(static fn(Variation $variation): int => $variation->id(), $product->variations());
        $this->products->remove($product);
        foreach ($variationIds as $variationId) {
            $this->content->deleteVariation($variationId);
        }
        $this->events->dispatch($removed);
        foreach ($files as $file) {
            $this->events->dispatch(new DownloadRemoved($file->storageKey()));
        }
    }

    /**
     * @throws ProductNotFound      also when the product's commerce details were never saved
     * @throws CatalogRuleViolation
     */
    public function addVariation(int $productId, SaveVariation $command): VariationView
    {
        $product = $this->get($productId);
        $this->refuseTakenSku($command->sku, $productId, null);
        $details = $this->variationDetails($command);
        $names = $this->optionNames($product, $details->optionIds);

        // The post first: the variation takes its id. Should the variation be refused, the post goes again.
        $id = $this->content->createVariation($productId, trim($this->content->name($productId) . ' – ' . $names, ' –'));
        try {
            $variation = $product->addVariation($id, $details);
            $this->products->save($product);
        } catch (\Throwable $e) {
            $this->content->deleteVariation($id);

            throw $e;
        }
        $this->events->dispatch(new ProductChanged($productId));

        return VariationView::fromVariation($variation);
    }

    /**
     * A copy of the product to start another from: a draft, "(copy)" after its name, with its texts, terms and
     * pictures, its prices, attributes and variations, but no SKUs (they are each one item's), no stock and no
     * downloads (their files are the product's own).
     *
     * @throws ProductNotFound
     */
    public function duplicate(int $id): ProductView
    {
        $original = $this->product($id);
        $copy = $this->content->copy($id);
        $attributes = array_map(static fn(AttributeView $attribute): SaveAttribute => new SaveAttribute(
            $attribute->name,
            array_map(static fn(OptionView $option): SaveOption => new SaveOption($option->name, $option->adjustment, null, $option->adjustmentKind), $attribute->options),
            $attribute->required,
            $attribute->priceHidden,
        ), $original->attributes);
        if (!$original->saved) {
            return $this->product($copy);
        }
        $saved = $this->save($copy, SaveProduct::unchanged($original, ['sku' => null, 'attributes' => $attributes]));
        // The copy's options are new: each takes the place of the original's at the same place.
        $options = [];
        foreach ($original->attributes as $a => $attribute) {
            foreach ($attribute->options as $o => $option) {
                $copied = $saved->attributes[$a]->options[$o] ?? null;
                $options[$option->id] = $copied?->id;
            }
        }
        foreach ($original->variations as $variation) {
            $optionIds = array_map(static fn(int $option): int => $options[$option] ?? $option, $variation->optionIds);
            $this->addVariation($copy, SaveVariation::unchanged($variation, ['optionIds' => $optionIds, 'sku' => null]));
        }

        return $this->product($copy);
    }

    /**
     * @throws ProductNotFound
     * @throws VariationNotFound
     * @throws CatalogRuleViolation
     */
    public function changeVariation(int $productId, int $variationId, SaveVariation $command): VariationView
    {
        $product = $this->get($productId);
        $this->refuseTakenSku($command->sku, null, $variationId);
        $variation = $product->changeVariation($variationId, $this->variationDetails($command));
        $this->products->save($product);
        $this->events->dispatch(new ProductChanged($productId));

        return VariationView::fromVariation($variation);
    }

    /**
     * @throws ProductNotFound
     * @throws VariationNotFound
     */
    public function removeVariation(int $productId, int $variationId): void
    {
        $product = $this->get($productId);
        $product->removeVariation($variationId);
        $this->products->save($product);
        $this->content->deleteVariation($variationId);
        $this->events->dispatch(new ProductChanged($productId));
        $this->events->dispatch(new CatalogItemsRemoved(variationIds: [$variationId]));
    }

    /**
     * Forgets a variation whose post is being deleted, unless the shop removed it already.
     */
    public function forgetVariation(int $variationId): void
    {
        $product = $this->products->findByVariation($variationId);
        if ($product !== null) {
            $this->removeVariation($product->id(), $variationId);
        }
    }

    /**
     * The names of the chosen options, as a variation's label shows them: "M / Blue".
     *
     * @param list<int> $optionIds
     */
    private function optionNames(Product $product, array $optionIds): string
    {
        $names = [];
        foreach ($product->offeredOptions() as [, $options]) {
            foreach ($options as $option) {
                if (\in_array($option->id(), $optionIds, true)) {
                    $names[] = $option->name();
                }
            }
        }

        return implode(' / ', $names);
    }

    private function get(int $id): Product
    {
        return $this->products->find($id) ?? throw new ProductNotFound($id);
    }

    private function name(int $id): string
    {
        if (!$this->content->exists($id)) {
            throw new ProductNotFound($id);
        }

        return $this->content->name($id);
    }

    private function refuseTakenSku(?string $sku, ?int $exceptProductId, ?int $exceptVariationId): void
    {
        if ($sku !== null && $this->products->isSkuTaken($sku, $exceptProductId, $exceptVariationId)) {
            throw new CatalogRuleViolation('Another product or variation has the SKU "%s".', 'orillacart_duplicate_sku', values: [$sku]);
        }
    }

    private function variationDetails(SaveVariation $command): VariationDetails
    {
        return new VariationDetails(
            array_values(array_unique($command->optionIds)),
            CatalogDrafts::pricing($command->price, $command->sale),
            $command->sku,
            StockMode::from($command->stockMode),
            $command->weight,
            $command->length,
            $command->width,
            $command->height,
            $command->backorders === null ? null : Backorders::from($command->backorders),
            $command->backorderLimit,
            $command->lowStockThreshold,
        );
    }

    /**
     * Checks that every item of a bundle can be sold inside it: a product with commerce details and nothing to
     * choose, or variations of a product with variations; never a bundle.
     *
     * @throws CatalogRuleViolation
     */
    private function bundle(SaveBundle $command): Bundle
    {
        $items = [];
        foreach ($command->items as $item) {
            $product = $this->products->find($item->productId)
                ?? throw new CatalogRuleViolation('Product %d has no commerce details to sell in a bundle.', 'orillacart_invalid_bundle', values: [$item->productId]);
            $name = $this->content->name($item->productId);
            if ($product->isBundle()) {
                throw new CatalogRuleViolation('%s is a bundle itself; add its items instead.', 'orillacart_invalid_bundle', values: [$name]);
            }
            if ($product->hasVariations()) {
                $offered = array_map(static fn(Variation $variation): int => $variation->id(), $product->variations());
                if ($item->variationIds === []) {
                    throw new CatalogRuleViolation('Choose which variations of %s the bundle offers.', 'orillacart_invalid_bundle', values: [$name]);
                }
                if (array_diff($item->variationIds, $offered) !== []) {
                    throw new CatalogRuleViolation('%s has no such variation.', 'orillacart_invalid_bundle', values: [$name]);
                }
            } elseif ($item->variationIds !== []) {
                throw new CatalogRuleViolation('%s has no variations.', 'orillacart_invalid_bundle', values: [$name]);
            } else {
                foreach ($product->offeredOptions() as [$attribute]) {
                    if ($attribute->isRequired()) {
                        throw new CatalogRuleViolation('%1$s asks the customer to choose %2$s, which a bundle cannot.', 'orillacart_invalid_bundle', values: [$name, strtolower($attribute->name())]);
                    }
                }
            }
            $items[] = new BundleItem($item->productId, array_values(array_unique($item->variationIds)), $item->quantity, $item->optional);
        }

        return new Bundle($items, $command->pricing, $command->discount === null || $command->discount === '' ? null : Money::of($command->discount));
    }

    /**
     * A bundle item's product name, or one of its variations' options.
     */
    private function itemName(int $productId, ?int $variationId): string
    {
        if ($variationId === null) {
            return $this->content->name($productId);
        }
        foreach ($this->products->find($productId)?->variations() ?? [] as $variation) {
            if ($variation->id() === $variationId) {
                return implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options()));
            }
        }

        return '#' . $variationId;
    }

    /**
     * The shop measures in other units now: every product's and variation's weight and sides converted, so
     * nothing weighs differently than it did.
     */
    public function convertMeasures(float $weightFactor, float $lengthFactor): void
    {
        $after = 0;
        do {
            $ids = $this->products->ids($after, 100);
            foreach ($ids as $id) {
                $product = $this->products->find($id);
                if ($product !== null) {
                    $product->measuredIn($weightFactor, $lengthFactor);
                    $this->products->save($product);
                }
                $after = $id;
            }
        } while (\count($ids) === 100);
    }

    private static function pricedByItself(Product $product): bool
    {
        return !$product->hasVariations() && $product->bundle()?->pricing !== Bundle::SUM;
    }
}
