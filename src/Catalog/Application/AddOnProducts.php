<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Domain\MarkedProduct;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Extension\Catalog\Products;
use OrillaCart\Extension\Catalog\ProductSummary;
use OrillaCart\Extension\Catalog\ProductVariationSummary;

/**
 * The shop's products as the extension API gives them to add-ons: marked for their filters, and read as shoppers
 * see them (design 61).
 */
final readonly class AddOnProducts implements Products
{
    public function __construct(
        private ProductRepository $products,
        private ProductTranslations $translations,
        private ShopCatalog $catalog,
    ) {}

    public function find(int $productId): ?ProductSummary
    {
        try {
            $offer = $this->catalog->offer($productId);
        } catch (ProductNotFound) {
            return null;
        }

        return new ProductSummary(
            $offer->id,
            $offer->name,
            $offer->type,
            $offer->sku,
            $offer->price,
            $offer->regularPrice,
            $offer->forSale,
            $offer->available,
            $offer->onBackorder,
            array_map(static fn(OfferedVariation $variation): ProductVariationSummary => new ProductVariationSummary($variation->id, $variation->label, $variation->sku, $variation->price, $variation->regularPrice, $variation->forSale, $variation->available), $offer->variations),
        );
    }

    public function mark(int $productId, string $filterId): bool
    {
        $productId = $this->translations->original($productId);
        if (preg_match(MarkedProduct::MARK, $filterId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a product filter\'s id: lowercase letters, digits and underscores, up to 40.', $filterId));
        }

        return $this->products->mark($productId, $filterId);
    }

    public function unmark(int $productId, string $filterId): void
    {
        $productId = $this->translations->original($productId);
        $this->products->unmark($productId, $filterId);
    }

    public function isMarked(int $productId, string $filterId): bool
    {
        $productId = $this->translations->original($productId);
        return $this->products->isMarked($productId, $filterId);
    }
}
