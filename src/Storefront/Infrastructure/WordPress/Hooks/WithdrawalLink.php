<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Application\LegalSettingsService;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * "Withdraw from contract here" at the foot of every page, set apart from the rest of the footer, while the
 * shop has a published withdrawal page: the EU asks that consumers reach the withdrawal function from anywhere,
 * whatever the theme's footer holds.
 */
#[AsHook('wp_footer')]
final readonly class WithdrawalLink
{
    public function __construct(
        private LegalSettingsService $legal,
        private ShopLinks $links,
    ) {}

    public function __invoke(): void
    {
        $page = $this->legal->current()->withdrawalForm;
        if ($page === null || get_post_status($page) !== 'publish') {
            return;
        }
        // The page in the shopper's language, not linked from itself.
        $page = $this->links->pageInLanguage($page);
        if (get_queried_object_id() === $page) {
            return;
        }
        $link = get_permalink($page);
        if (!\is_string($link)) {
            return;
        }
        printf(
            '<p class="orillacart-withdrawal-link"><a href="%s">%s</a></p>',
            esc_url($link),
            esc_html__('Withdraw from contract here', 'orillacart'),
        );
    }
}
