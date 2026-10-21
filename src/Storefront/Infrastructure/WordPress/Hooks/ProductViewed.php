<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * Announces each view of a product's page (orillacart_product_viewed: product id, customer id or null), for
 * add-ons that count them on the server. A page cache answers without WordPress, so views it serves are not
 * heard of: the storefront script's orillacart:view_item event hears of every one.
 */
#[AsHook('template_redirect', method: 'viewed', priority: 20)]
final readonly class ProductViewed
{
    public function __construct(private Shopper $shopper) {}

    public function viewed(): void
    {
        if (is_singular(ProductPostType::NAME)) {
            do_action('orillacart_product_viewed', get_queried_object_id(), $this->shopper->customerId());
        }
    }
}
