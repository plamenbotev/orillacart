<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Application\StorefrontSettingsService;

/**
 * The shop's notice over its pages, for a header: the words are the shop's (Settings → Storefront), in the
 * language shown, so every page with the block says the same. Nothing while the notice is off or has no text.
 */
final readonly class StoreNoticeBlock implements BlockDefinition
{
    public function __construct(
        private StorefrontSettingsService $storefront,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/store-notice';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $notice = $this->storefront->shownNotice();
        if ($notice === null) {
            return '';
        }
        // A page of the site is on this site, in the language shown where the language plugin says so.
        $link = $notice->link === '' ? '' : esc_url(str_starts_with($notice->link, '/') ? home_url($notice->link) : $notice->link);

        return $this->templates->render('storefront/store-notice.html.twig', [
            'text' => $notice->text,
            'link' => $link,
            'link_label' => $notice->linkLabel,
            'dismissible' => $notice->dismissible,
            // Sites of a network may share an address, and with it the shopper's browser storage.
            'key' => substr(hash('sha256', implode("\n", [get_current_blog_id(), $notice->text, $link, $notice->linkLabel])), 0, 16),
        ]);
    }
}
