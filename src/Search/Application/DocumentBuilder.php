<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\OptionTranslations;
use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Search\Application\Dto\DocumentOption;
use OrillaCart\Search\Application\Dto\DocumentVariant;
use OrillaCart\Search\Application\Dto\ProductText;
use OrillaCart\Search\Application\Dto\SearchDocument;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\Dto\ContentLanguageView;

/**
 * Flattens a product into its search document: the catalog's offer as shoppers see it today, with the
 * site's content. A product that is not published, or has no commerce details, has none. On a site in several
 * languages the document has its translations' words too, and its options' names in each language (design 51).
 */
final readonly class DocumentBuilder
{
    public function __construct(
        private ShopCatalog $catalog,
        private ProductTexts $texts,
        private PriceHistory $history,
        private ContentTranslations $translations,
    ) {}

    public function build(int $productId): ?SearchDocument
    {
        return $this->buildMany([$productId])[$productId];
    }

    /**
     * The documents of many products, read together: the catalog's offers and the site's content in a few
     * queries for the batch rather than a few for each product.
     *
     * @param list<int> $productIds
     *
     * @return array<int, ?SearchDocument> by product id; null for one that has none
     */
    public function buildMany(array $productIds): array
    {
        $texts = $this->texts->describe($productIds);
        $offers = $this->catalog->offers(array_keys($texts));
        // The index sees every change of a price, sales starting and ending too: the history keeps them.
        $this->history->record(array_values($offers));
        $labels = $this->optionLabels(array_values($offers));
        $documents = [];
        foreach ($productIds as $productId) {
            $documents[$productId] = isset($texts[$productId], $offers[$productId]) ? $this->document($offers[$productId], $texts[$productId], $labels) : null;
        }

        return $documents;
    }

    /**
     * The options' names in the site's other languages, by option id and locale: the shop's translations of them.
     *
     * @param list<ProductOffer> $offers
     *
     * @return array<int, array<string, string>>
     */
    private function optionLabels(array $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            foreach ($offer->attributes as $attribute) {
                foreach ($attribute->options as $option) {
                    $ids[] = $option->id;
                }
            }
        }
        $labels = [];
        foreach ($this->translations->languages() as $language) {
            \assert($language instanceof ContentLanguageView);
            if ($language->default || $ids === []) {
                continue;
            }
            foreach ($this->translations->translated(OptionTranslations::TYPE, $ids, 'name', $language->locale) as $id => $name) {
                $labels[$id][$language->locale] = $name;
            }
        }

        return $labels;
    }

    /**
     * @param array<int, array<string, string>> $labels the options' names in other languages
     */
    private function document(ProductOffer $offer, ProductText $text, array $labels): SearchDocument
    {
        $productId = $offer->id;

        $price = static fn(string $price): ?string => $offer->priceHidden ? null : $price;
        if ($offer->variations === []) {
            $variants = [new DocumentVariant($offer->id, $price($offer->price), $offer->available !== 0)];
            $options = self::options($offer->attributes, null, $labels);
        } else {
            $variants = array_map(static fn(OfferedVariation $variation): DocumentVariant => new DocumentVariant(
                $variation->id,
                $price($variation->price),
                $variation->available !== 0,
                self::options($offer->attributes, $variation->optionIds, $labels),
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
            $text->tags,
            $text->locale,
            $text->translations,
        );
    }

    /**
     * @param list<AttributeView> $attributes
     * @param list<int>|null      $optionIds  only these; null for all
     * @param array<int, array<string, string>> $labels the options' names in other languages
     *
     * @return list<DocumentOption>
     */
    private static function options(array $attributes, ?array $optionIds, array $labels): array
    {
        $options = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->options as $option) {
                if ($optionIds === null || \in_array($option->id, $optionIds, true)) {
                    $options[] = new DocumentOption($attribute->name, $option->name, array_filter($labels[$option->id] ?? [], static fn(string $label): bool => $label !== $option->name));
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
