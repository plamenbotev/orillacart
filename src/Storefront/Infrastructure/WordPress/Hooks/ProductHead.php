<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductIdentifiers;
use OrillaCart\Reviews\Application\Dto\ReviewView;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * What a product's page tells others in its head: search engines, through schema.org Product data (its price
 * and stock today, identifiers, brand, images, stars and latest reviews), and social sites and chat apps, through
 * Open Graph tags, unless an SEO plugin writes those already. The shop chooses both (Settings → Storefront);
 * add-ons change the data on the filter orillacart_product_structured_data.
 */
#[AsHook('wp_head', priority: 5)]
final readonly class ProductHead
{
    /** Constants SEO plugins that write Open Graph tags define. */
    private const array SEO_PLUGINS = ['WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION', 'SLIM_SEO_VER'];
    private const int REVIEWS_SHOWN = 5;

    public function __construct(
        private ShopCatalog $catalog,
        private Shopper $shopper,
        private Reviews $reviews,
        private SettingsService $shop,
        private StorefrontSettingsService $settings,
    ) {}

    public function __invoke(): void
    {
        if (!is_singular(ProductPostType::NAME)) {
            return;
        }
        $id = get_queried_object_id();
        try {
            $offer = $this->catalog->offer($id, $this->shopper->customerId());
        } catch (ProductNotFound) {
            return;
        }
        $settings = $this->settings->current();
        if ($settings->structuredData) {
            $data = apply_filters('orillacart_product_structured_data', $this->structuredData($id, $offer), $id);
            if (\is_array($data) && $data !== []) {
                echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
            }
        }
        if ($settings->sharingTags === SharingTags::Always || ($settings->sharingTags === SharingTags::Auto && !self::seoPluginActive())) {
            foreach ($this->sharingTags($id, $offer) as $property => $content) {
                echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr($content) . "\">\n";
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredData(int $id, ProductOffer $offer): array
    {
        $url = (string) get_permalink($id);
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $url . '#product',
            'name' => $offer->name,
            'url' => $url,
            'description' => self::description($id),
            'image' => self::images($id),
        ];
        $sku = $offer->sku;
        if ($sku !== null && $sku !== '') {
            $data['sku'] = $sku;
        }
        $gtin = ProductIdentifiers::gtinOf($id);
        if ($gtin !== '') {
            $data['gtin'] = $gtin;
        }
        $mpn = ProductIdentifiers::mpnOf($id);
        if ($mpn !== '') {
            $data['mpn'] = $mpn;
        }
        $brands = get_the_terms($id, ProductBrandTaxonomy::NAME);
        if (\is_array($brands) && $brands !== []) {
            $data['brand'] = ['@type' => 'Brand', 'name' => html_entity_decode($brands[0]->name, ENT_QUOTES)];
        }
        if (!$offer->priceHidden) {
            $currency = $this->shop->current()->currency;
            $data['offers'] = $offer->highestPrice !== null
                ? ['@type' => 'AggregateOffer', 'lowPrice' => $offer->price, 'highPrice' => $offer->highestPrice, 'offerCount' => \count($offer->variations), 'priceCurrency' => $currency, 'availability' => self::availability($offer)]
                : ['@type' => 'Offer', 'price' => $offer->price, 'priceCurrency' => $currency, 'availability' => self::availability($offer), 'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url];
        }
        $page = $this->reviews->shown() ? $this->reviews->page($id) : null;
        if ($page?->rating !== null) {
            $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $page->rating->average, 'reviewCount' => $page->rating->count, 'bestRating' => 5, 'worstRating' => 1];
            $data['review'] = array_map(static fn(ReviewView $review): array => [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $review->author],
                'datePublished' => substr($review->writtenAt, 0, 10),
                'reviewBody' => $review->text,
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $review->rating, 'bestRating' => 5, 'worstRating' => 1],
            ], \array_slice($page->reviews, 0, self::REVIEWS_SHOWN));
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function sharingTags(int $id, ProductOffer $offer): array
    {
        $tags = [
            'og:type' => 'product',
            'og:title' => $offer->name,
            'og:description' => mb_substr(self::description($id), 0, 300),
            'og:url' => (string) get_permalink($id),
            'og:site_name' => html_entity_decode(get_bloginfo('name'), ENT_QUOTES),
        ];
        $image = self::images($id)[0] ?? null;
        if ($image !== null) {
            $tags['og:image'] = $image;
        }
        if (!$offer->priceHidden) {
            $tags['product:price:amount'] = $offer->price;
            $tags['product:price:currency'] = $this->shop->current()->currency;
            $tags['product:availability'] = match (self::availability($offer)) {
                'https://schema.org/InStock' => 'in stock',
                'https://schema.org/BackOrder' => 'available for order',
                default => 'out of stock',
            };
        }

        return array_filter($tags, static fn(string $content): bool => $content !== '');
    }

    private static function availability(ProductOffer $offer): string
    {
        return match (true) {
            !$offer->forSale, $offer->soldOut => 'https://schema.org/OutOfStock',
            $offer->onBackorder => 'https://schema.org/BackOrder',
            default => 'https://schema.org/InStock',
        };
    }

    private static function description(int $id): string
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        $text = $post->post_excerpt !== '' ? $post->post_excerpt : strip_shortcodes(excerpt_remove_blocks($post->post_content));
        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($plain, 0, 5000);
    }

    /**
     * The featured image, then the gallery's, large.
     *
     * @return list<string>
     */
    private static function images(int $id): array
    {
        $urls = [];
        foreach (array_unique([(int) get_post_thumbnail_id($id), ...ProductGallery::of($id)]) as $image) {
            $source = $image > 0 ? wp_get_attachment_image_url($image, 'large') : false;
            if (\is_string($source)) {
                $urls[] = $source;
            }
        }

        return $urls;
    }

    private static function seoPluginActive(): bool
    {
        $active = false;
        foreach (self::SEO_PLUGINS as $constant) {
            $active = $active || \defined($constant);
        }

        return (bool) apply_filters('orillacart_seo_plugin_active', $active);
    }
}
