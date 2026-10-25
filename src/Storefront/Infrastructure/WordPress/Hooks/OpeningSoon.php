<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Application\ShopOpening;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * While the shop opens soon, a visitor to its pages, products and categories sees "Opening soon" instead; the
 * rest of the site stays as it is. 503, for a while: search engines come back, and page caches keep nothing.
 */
#[AsHook('template_redirect', priority: 1)]
final readonly class OpeningSoon
{
    public function __construct(
        private ShopOpening $opening,
        private ShopLinks $links,
        private TemplateRenderer $templates,
    ) {}

    public function __invoke(): void
    {
        if (!$this->opening->closedToVisitor() || !$this->isShop()) {
            return;
        }
        status_header(503);
        header('Retry-After: 3600');
        nocache_headers();
        echo $this->templates->render('storefront/opening-soon.html.twig', [ // phpcs:ignore WordPress.Security.EscapeOutput
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'locale' => get_bloginfo('language'),
        ]);
        exit;
    }

    private function isShop(): bool
    {
        $taxonomies = get_object_taxonomies(ProductPostType::NAME);

        return is_singular(ProductPostType::NAME)
            || is_post_type_archive(ProductPostType::NAME)
            || ($taxonomies !== [] && is_tax($taxonomies))
            || (is_page() && \in_array(get_queried_object_id(), $this->links->pageIds(), true));
    }
}
