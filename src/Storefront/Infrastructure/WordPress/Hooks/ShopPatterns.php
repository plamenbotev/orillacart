<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * Patterns for landing pages, in the editor's "Shop" category: a hero with featured products, the categories, a
 * sale banner, new arrivals, one product in the spotlight, a gift card (while the shop sells one), and a launch
 * page made of them. Core blocks and the shop's own, so the owner changes them as any block; the products come
 * from the shop as it is when the page is shown.
 */
#[AsHook('init', priority: 20)]
final readonly class ShopPatterns
{
    public const string CATEGORY = 'orillacart';

    /** The Products block as a plain row of products, without the shop's search, links and pages. */
    private const string ROW = '"filters":false,"showCategories":false,"showSearch":false,"showSort":false,"showLayouts":false,"showCount":false,"showPagination":false';

    public function __construct(
        private ShopLinks $links,
        private GiftCardService $giftCards,
    ) {}

    public function __invoke(): void
    {
        if (!\function_exists('register_block_pattern')) {
            return;
        }
        register_block_pattern_category(self::CATEGORY, ['label' => __('Shop', 'orillacart')]);
        foreach ($this->patterns() as $name => [$title, $description, $content]) {
            register_block_pattern('orillacart/' . $name, [
                'title' => $title,
                'description' => $description,
                'categories' => [self::CATEGORY],
                'postTypes' => ['page', 'wp_template', 'wp_template_part'],
                'content' => $content,
            ]);
        }
    }

    /**
     * @return array<string, array{string, string, string}> title, description and blocks of each
     */
    private function patterns(): array
    {
        $patterns = [
            'shop-hero' => [__('Shop hero', 'orillacart'), __('A large banner with a button to the shop, then four featured products.', 'orillacart'), $this->hero()],
            'category-showcase' => [__('Shop by category', 'orillacart'), __('The shop’s top categories with their pictures.', 'orillacart'), $this->categories()],
            'sale-banner' => [__('Sale banner', 'orillacart'), __('A banner for the sale, with the products on sale.', 'orillacart'), $this->sale()],
            'new-arrivals' => [__('New arrivals', 'orillacart'), __('The newest products.', 'orillacart'), $this->newest()],
            'product-spotlight' => [__('Product spotlight', 'orillacart'), __('One product with its pictures, price and buying form: choose it in the Single product block.', 'orillacart'), $this->spotlight(0)],
        ];
        $giftCard = $this->giftCard();
        if ($giftCard !== null) {
            $patterns['gift-card'] = [__('Gift card', 'orillacart'), __('The shop’s gift card, to buy from the page.', 'orillacart'), $giftCard];
        }
        $patterns['launch-page'] = [
            __('Launch page', 'orillacart'),
            __('A whole landing page: the hero, the categories, the newest products and the sale.', 'orillacart'),
            implode("\n", [$this->hero(), $this->categories(), $this->newest(), $this->sale()]),
        ];

        return $patterns;
    }

    private function hero(): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:cover {"dimRatio":60,"overlayColor":"contrast","minHeight":420,"align":"full","layout":{"type":"constrained"}} -->
                <div class="wp-block-cover alignfull" style="min-height:420px"><span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-60 has-background-dim"></span><div class="wp-block-cover__inner-container">
                <!-- wp:heading {"textAlign":"center","level":1} -->
                <h1 class="wp-block-heading has-text-align-center">%s</h1>
                <!-- /wp:heading -->
                <!-- wp:paragraph {"align":"center"} -->
                <p class="has-text-align-center">%s</p>
                <!-- /wp:paragraph -->
                %s
                </div></div>
                <!-- /wp:cover -->
                <!-- wp:orillacart/products {"perPage":4,"columns":4,"featured":true,%s,"align":"wide"} /-->
                HTML,
            esc_html__('Made to be used every day', 'orillacart'),
            esc_html__('Our favourites, chosen for you.', 'orillacart'),
            $this->button(__('Shop now', 'orillacart')),
            self::ROW,
        );
    }

    private function categories(): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:heading {"textAlign":"center"} -->
                <h2 class="wp-block-heading has-text-align-center">%s</h2>
                <!-- /wp:heading -->
                <!-- wp:orillacart/product-categories {"source":"top","columns":4,"showImage":true,"align":"wide"} /-->
                HTML,
            esc_html__('Shop by category', 'orillacart'),
        );
    }

    private function sale(): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:group {"align":"full","backgroundColor":"accent-1","layout":{"type":"constrained"}} -->
                <div class="wp-block-group alignfull has-accent-1-background-color has-background">
                <!-- wp:heading {"textAlign":"center"} -->
                <h2 class="wp-block-heading has-text-align-center">%s</h2>
                <!-- /wp:heading -->
                <!-- wp:paragraph {"align":"center"} -->
                <p class="has-text-align-center">%s</p>
                <!-- /wp:paragraph -->
                <!-- wp:orillacart/products {"perPage":8,"columns":4,"onSale":true,%s,"align":"wide"} /-->
                </div>
                <!-- /wp:group -->
                HTML,
            esc_html__('On sale now', 'orillacart'),
            esc_html__('While the sale lasts.', 'orillacart'),
            self::ROW,
        );
    }

    private function newest(): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:heading {"textAlign":"center"} -->
                <h2 class="wp-block-heading has-text-align-center">%s</h2>
                <!-- /wp:heading -->
                <!-- wp:orillacart/products {"perPage":4,"columns":4,"sort":"newest",%s,"align":"wide"} /-->
                %s
                HTML,
            esc_html__('New arrivals', 'orillacart'),
            self::ROW,
            $this->button(__('See all products', 'orillacart')),
        );
    }

    /** One product: the pictures beside its name, stars, price and buying form. */
    private function spotlight(int $productId): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:orillacart/product {"productId":%d,"align":"wide"} -->
                <!-- wp:columns {"align":"wide"} -->
                <div class="wp-block-columns alignwide">
                <!-- wp:column -->
                <div class="wp-block-column"><!-- wp:orillacart/product-gallery /--></div>
                <!-- /wp:column -->
                <!-- wp:column -->
                <div class="wp-block-column">
                <!-- wp:orillacart/product-title {"level":2,"isLink":true} /-->
                <!-- wp:orillacart/product-rating /-->
                <!-- wp:orillacart/product-price /-->
                <!-- wp:orillacart/add-to-cart {"showDownloads":false} /-->
                </div>
                <!-- /wp:column -->
                </div>
                <!-- /wp:columns -->
                <!-- /wp:orillacart/product -->
                HTML,
            $productId,
        );
    }

    /** The first gift card the shop sells, in the spotlight; null while it sells none. */
    private function giftCard(): ?string
    {
        foreach ($this->giftCards->settings()->productIds as $id) {
            if (get_post_type($id) === ProductPostType::NAME && get_post_status($id) === 'publish') {
                return \sprintf(
                    <<<'HTML'
                        <!-- wp:heading {"textAlign":"center"} -->
                        <h2 class="wp-block-heading has-text-align-center">%s</h2>
                        <!-- /wp:heading -->
                        <!-- wp:paragraph {"align":"center"} -->
                        <p class="has-text-align-center">%s</p>
                        <!-- /wp:paragraph -->
                        %s
                        HTML,
                    esc_html__('Give a gift card', 'orillacart'),
                    esc_html__('Sent by email at once, to spend on anything in the shop.', 'orillacart'),
                    $this->spotlight($id),
                );
            }
        }

        return null;
    }

    /** A centred button to the shop page (to the site's front page while the shop has none). */
    private function button(string $label): string
    {
        return \sprintf(
            <<<'HTML'
                <!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
                <div class="wp-block-buttons"><!-- wp:button -->
                <div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>
                <!-- /wp:button --></div>
                <!-- /wp:buttons -->
                HTML,
            esc_url($this->links->pages()['shop'] ?? home_url('/')),
            esc_html($label),
        );
    }
}
