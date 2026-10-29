<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\LinkedProducts;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Search\Application\ShopSearch;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Storefront\Application\StorefrontSettingsService;

/**
 * Which products to show beside others: a product's upsells and related products on its page, and the cart's
 * cross-sells. Related products share a category, or failing those a tag, and come from the search index, so
 * only published products in stock or on backorder show; the shop's order decides which.
 */
final readonly class Merchandise
{
    public const int SHOWN = 4;

    public function __construct(
        private ShopSearch $search,
        private ContentLanguages $languages,
        private StorefrontSettingsService $settings,
        private ProductTranslations $translations,
    ) {}

    /**
     * @return list<int>
     */
    public function upsells(int $productId, int $count = self::SHOWN): array
    {
        return \array_slice(LinkedProducts::upsellsOf($productId), 0, $count);
    }

    /**
     * Products in the product's categories, then its tags, besides itself and its upsells.
     *
     * @return list<int>
     */
    public function related(int $productId, int $count = self::SHOWN): array
    {
        // The index finds the product, which a translation's page is of.
        $leave = [$productId, $this->translations->original($productId), ...LinkedProducts::upsellsOf($productId)];
        $related = [];
        foreach ([ProductCategoryTaxonomy::NAME, ProductTagTaxonomy::NAME] as $taxonomy) {
            $terms = wp_get_post_terms($productId, $taxonomy, ['fields' => 'ids']);
            $termIds = \is_array($terms) ? array_values(array_filter(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $terms), static fn(int $id): bool => $id > 0)) : [];
            if ($termIds === []) {
                continue;
            }
            // The index files products under their own language's terms, which need not be this post's (design 51).
            $termIds = array_values(array_unique(array_merge($termIds, ...array_map(fn(int $id): array => array_values($this->languages->termTranslations($id, $taxonomy)), $termIds))));
            $untranslated = $this->settings->current()->listUntranslated;
            $found = $this->search->search($taxonomy === ProductCategoryTaxonomy::NAME
                ? new SearchQuery(categoryIds: $termIds, inStockOnly: true, limit: $count + \count($leave), untranslated: $untranslated)
                : new SearchQuery(tagIds: $termIds, inStockOnly: true, limit: $count + \count($leave), untranslated: $untranslated));
            foreach ($found->ids as $id) {
                if (!\in_array($id, $leave, true)) {
                    $related[$id] = $id;
                }
            }
            if (\count($related) >= $count) {
                break;
            }
        }

        return \array_slice(array_values($related), 0, $count);
    }

    /**
     * The cross-sells of the products in the cart, besides those in it, in the order of the cart.
     *
     * @param list<int> $cartProductIds
     *
     * @return list<int>
     */
    public function crossSells(array $cartProductIds): array
    {
        $offered = [];
        foreach ($cartProductIds as $productId) {
            foreach (LinkedProducts::crossSellsOf($productId) as $id) {
                if (!\in_array($id, $cartProductIds, true)) {
                    $offered[$id] = $id;
                }
            }
        }

        return \array_slice(array_values($offered), 0, self::SHOWN);
    }
}
