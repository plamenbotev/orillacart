<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentCatalog;
use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\Dto\SearchQuery;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductCards;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopListing;

/**
 * The shop's products as a UCP catalogue: the shop page's search (ShopListing) and its cards (ProductCards), so
 * an agent finds what a shopper finds, published and not behind a password, priced as the shop shows a visitor.
 * A product's variants are its variations, or, for a simple product, itself; a variant's id is what checkout
 * takes (AgentCheckouts::ITEM). Amounts are in the currency's smallest unit.
 */
final readonly class ShopAgentCatalog implements AgentCatalog
{
    public function __construct(
        private ShopListing $listing,
        private ProductCards $cards,
        private Currencies $currencies,
    ) {}

    public function search(?string $query, array $categories, ?int $priceMin, ?int $priceMax, ?string $cursor, int $limit): array
    {
        $page = $cursor !== null && preg_match('/^p([1-9][0-9]{0,4})$/', $cursor, $match) === 1 ? (int) $match[1] : 1;
        $words = SearchQuery::words($query ?? '');
        $currency = $this->currencies->current();
        $choices = [
            'category' => '',
            'category_id' => self::category($categories),
            'tag' => '',
            'tag_id' => null,
            'search' => $words,
            'brands' => [],
            'options' => [],
            'price_min' => $priceMin === null ? null : $this->major($priceMin, $currency),
            'price_max' => $priceMax === null ? null : $this->major($priceMax, $currency),
            'in_stock' => false,
            'sort' => $words === '' ? 'order' : 'relevance',
            'page' => $page,
            'view' => '',
        ];
        $result = $this->listing->search($choices, ($page - 1) * $limit, $limit, false);
        $products = $this->products($result->ids, $currency);
        $more = $page * $limit < $result->total;

        return ['products' => $products, 'pagination' => ['has_next_page' => $more, 'total_count' => $result->total] + ($more ? ['cursor' => 'p' . ($page + 1)] : [])];
    }

    public function lookup(array $ids): array
    {
        $productIds = [];
        foreach (\array_slice($ids, 0, 50) as $id) {
            if (preg_match(AgentCheckouts::ITEM, $id, $match) === 1) {
                $productIds[] = (int) $match[1];
            }
        }

        return $this->products(array_values(array_unique($productIds)), $this->currencies->current());
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function products(array $ids, string $currency): array
    {
        $products = [];
        foreach ($this->cards->cards($ids, true) as $card) {
            $offer = $card['offer'];
            if (!$offer instanceof ProductOffer || $offer->priceHidden) {
                continue;
            }
            $products[] = $this->product($offer, \is_string($card['url'] ?? null) ? $card['url'] : '', \is_string($card['excerpt'] ?? null) ? $card['excerpt'] : '', $currency);
        }

        return $products;
    }

    /** @return array<string, mixed> */
    private function product(ProductOffer $offer, string $url, string $excerpt, string $currency): array
    {
        $media = self::media($offer->id);
        $variants = $offer->variations === []
            ? [$this->variant('prod_' . $offer->id, $offer->name, $offer->price, $offer->regularPrice, $offer->forSale && !$offer->soldOut, $offer->sku, $url, $currency)]
            : array_map(fn(OfferedVariation $v): array => $this->variant('prod_' . $offer->id . '_var_' . $v->id, $v->label, $v->price, $v->regularPrice, $v->forSale && !$v->soldOut, $v->sku, $url, $currency), $offer->variations);
        $low = $offer->price;
        $high = $offer->highestPrice ?? $offer->price;
        $product = [
            'id' => 'prod_' . $offer->id,
            'title' => $offer->name,
            'description' => ['plain' => trim(wp_strip_all_tags($excerpt))],
            'url' => $url,
            'price_range' => ['min' => $this->price($low, $currency), 'max' => $this->price($high, $currency)],
            'variants' => $variants,
        ];
        if ($offer->regularPrice !== null) {
            $product['list_price_range'] = ['min' => $this->price($offer->regularPrice, $currency), 'max' => $this->price($offer->regularPrice, $currency)];
        }
        if ($media !== []) {
            $product['media'] = $media;
        }
        $categories = get_the_terms($offer->id, ProductCategoryTaxonomy::NAME);
        if (\is_array($categories) && $categories !== []) {
            $product['categories'] = array_values(array_map(static fn(\WP_Term $term): array => ['value' => $term->name, 'taxonomy' => 'merchant'], $categories));
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function variant(string $id, string $title, string $price, ?string $regular, bool $available, ?string $sku, string $url, string $currency): array
    {
        return ['id' => $id, 'title' => $title, 'description' => ['plain' => $title], 'price' => $this->price($price, $currency), 'availability' => ['available' => $available], 'url' => $url]
            + ($regular === null ? [] : ['list_price' => $this->price($regular, $currency)])
            + ($sku === null || $sku === '' ? [] : ['sku' => $sku]);
    }

    /** @return list<array<string, mixed>> */
    private static function media(int $productId): array
    {
        $picture = (int) get_post_thumbnail_id($productId);
        $source = $picture > 0 ? wp_get_attachment_image_src($picture, 'large') : false;
        if ($source === false) {
            return [];
        }
        $alt = get_post_meta($picture, '_wp_attachment_image_alt', true);

        return [['type' => 'image', 'url' => $source[0], 'alt_text' => \is_string($alt) ? $alt : '', 'width' => $source[1], 'height' => $source[2]]];
    }

    /**
     * The first of the categories named, by id or name.
     *
     * @param list<string> $categories
     */
    private static function category(array $categories): ?int
    {
        foreach ($categories as $category) {
            $term = ctype_digit($category) ? get_term((int) $category, ProductCategoryTaxonomy::NAME) : get_term_by('name', $category, ProductCategoryTaxonomy::NAME);
            if ($term instanceof \WP_Term) {
                return $term->term_id;
            }
        }

        return $categories === [] ? null : 0;
    }

    /** @return array{amount: int, currency: string} */
    private function price(string $amount, string $currency): array
    {
        return ['amount' => (int) str_replace('.', '', Money::of($amount)->format($this->currencies->decimals($currency))), 'currency' => $currency];
    }

    private function major(int $minor, string $currency): string
    {
        $decimals = $this->currencies->decimals($currency);

        return $decimals === 0 ? (string) $minor : number_format($minor / 10 ** $decimals, $decimals, '.', '');
    }
}
