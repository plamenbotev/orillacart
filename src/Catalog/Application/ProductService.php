<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\Dto\VariationView;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
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
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @throws ProductNotFound when there is no product post with this id
     */
    public function product(int $id): ProductView
    {
        $name = $this->name($id);
        $product = $this->products->find($id);

        return $product === null ? ProductView::unsaved($id, $name) : ProductView::fromProduct($product, $name);
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
            throw new CatalogRuleViolation(\sprintf('Another product or variation has the SKU "%s".', $command->sku), 'orillacart_duplicate_sku');
        }
        if ($command->taxGroupId !== null && !$this->taxGroups->exists($command->taxGroupId)) {
            throw new CatalogRuleViolation(\sprintf('There is no tax group %d.', $command->taxGroupId), 'orillacart_unknown_tax_group');
        }
        $sets = array_map(
            fn(int $setId): AttributeSet => $this->sets->find($setId)
                ?? throw new CatalogRuleViolation(\sprintf('There is no attribute set %d.', $setId), 'orillacart_unknown_attribute_set'),
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
        $this->products->save($product);

        $this->events->dispatch(new ProductChanged($id));
        if ($removedOptions !== []) {
            $this->events->dispatch(new CatalogItemsRemoved(optionIds: $removedOptions));
        }

        return ProductView::fromProduct($product, $name);
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
        $this->products->remove($product);
        $this->events->dispatch($removed);
    }

    /**
     * @throws ProductNotFound      also when the product's commerce details were never saved
     * @throws CatalogRuleViolation
     */
    public function addVariation(int $productId, SaveVariation $command): VariationView
    {
        $product = $this->get($productId);
        $this->refuseTakenSku($command->sku, $productId, null);
        $variation = $product->addVariation($this->variationDetails($command));
        $this->products->save($product);
        $this->events->dispatch(new ProductChanged($productId));

        return VariationView::fromVariation($variation);
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
        $this->events->dispatch(new ProductChanged($productId));
        $this->events->dispatch(new CatalogItemsRemoved(variationIds: [$variationId]));
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
            throw new CatalogRuleViolation(\sprintf('Another product or variation has the SKU "%s".', $sku), 'orillacart_duplicate_sku');
        }
    }

    private function variationDetails(SaveVariation $command): VariationDetails
    {
        return new VariationDetails(
            array_values(array_unique($command->optionIds)),
            CatalogDrafts::pricing($command->price, $command->sale),
            $command->sku,
            StockMode::from($command->stockMode),
        );
    }
}
