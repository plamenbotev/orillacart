<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Application\StorefrontSettingsService;

/**
 * The products the shopper viewed lately, for any page: their browser keeps the list, and the script shows it.
 * Nothing shows while the shop keeps no such list (Settings → Storefront).
 */
final readonly class RecentlyViewedBlock implements BlockDefinition
{
    public function __construct(
        private StorefrontSettingsService $storefront,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/recently-viewed';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        if (!$this->storefront->current()->recentlyViewed) {
            return '';
        }

        return $this->templates->render('storefront/_recently_viewed.html.twig', [
            'count' => max(1, min(12, \is_int($attributes['count'] ?? null) ? $attributes['count'] : 4)),
            'exclude' => is_singular() ? get_queried_object_id() : null,
        ]);
    }
}
