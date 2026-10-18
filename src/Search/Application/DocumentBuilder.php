<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentVariant;
use OrillaCart\Search\Application\Dto\SearchDocument;

/**
 * Flattens a product into its search document: the catalog's offer as shoppers see it today, with the
 * site's content. A product that is not published, or has no commerce details, has none.
 */
final readonly class DocumentBuilder
{
    public function __construct(
        private ShopCatalog $catalog,
        private ProductTexts $texts,
    ) {}

    public function build(int $productId): ?SearchDocument
    {
        $text = $this->texts->describe($productId);
        if ($text === null) {
            return null;
        }
        try {
            $offer = $this->catalog->offer($productId);
        } catch (ProductNotFound) {
            return null;
        }

        $price = static fn(string $price): ?string => $offer->priceHidden ? null : $price;
        if ($offer->variations === []) {
            $variants = [new DocumentVariant($offer->id, $price($offer->price), $offer->available !== 0)];
            $options = self::options($offer->attributes, null);
        } else {
            $variants = array_map(static fn(OfferedVariation $variation): DocumentVariant => new DocumentVariant(
                $variation->id,
                $price($variation->price),
                $variation->available !== 0,
                self::options($offer->attributes, $variation->optionIds),
            ), $offer->variations);
            $options = [];
        }

        return new SearchDocument(
            $offer->id,
            $text->title,
            $text->text,
            self::skus($offer),
            $text->categories,
            $text->brands,
            $options,
            $variants,
            $offer->featured,
            self::onSale($offer),
            $text->menuOrder,
            $text->publishedAt,
            $this->catalog->nextPriceChange($productId),
        );
    }

    /**
     * @param list<AttributeView> $attributes
     * @param list<int>|null      $optionIds  only these; null for all
     *
     * @return list<DocumentOption>
     */
    private static function options(array $attributes, ?array $optionIds): array
    {
        $options = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->options as $option) {
                if ($optionIds === null || \in_array($option->id, $optionIds, true)) {
                    $options[] = new DocumentOption($attribute->name, $option->name);
                }
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function skus(ProductOffer $offer): array
    {
        $skus = [$offer->sku, ...array_map(static fn(OfferedVariation $variation): ?string => $variation->sku, $offer->variations)];

        return array_values(array_unique(array_filter($skus, static fn(?string $sku): bool => $sku !== null && $sku !== '')));
    }

    private static function onSale(ProductOffer $offer): bool
    {
        if ($offer->priceHidden) {
            return false;
        }
        if ($offer->variations === []) {
            return $offer->regularPrice !== null;
        }

        return array_filter($offer->variations, static fn(OfferedVariation $variation): bool => $variation->regularPrice !== null) !== [];
    }
}
