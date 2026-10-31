<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Checkout\Application\PromotionProgress;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\PromotionWords;
use OrillaCart\Storefront\Infrastructure\WordPress\RenderedPromotions;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * What the shopper's cart still needs for the shop's promotions, nearest first, with a bar: "Add 1 more Lamp to get
 * 1 free", "Spend €18.00 more to get 10% off", and those it gets already ("Buy 2, get 1 free — applied, you save
 * €9.00"). For the cart, the mini cart's sidebar or a header; nothing while there is nothing to tell.
 *
 * A visitor's page may come from a page cache, so it carries no one's cart: the storefront script fills it in.
 * A logged-in customer's, which caches do not keep, is filled in here.
 */
final readonly class PromotionProgressBlock implements BlockDefinition
{
    public function __construct(
        private PromotionProgress $progress,
        private PromotionWords $words,
        private Shopper $shopper,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/promotion-progress';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $applied = ($attributes['showApplied'] ?? true) !== false;
        $count = \is_int($attributes['count'] ?? null) ? max(1, min(RenderedPromotions::MOST, $attributes['count'])) : 3;

        return $this->templates->render('storefront/promotion-progress.html.twig', [
            'html' => $this->shopper->customerId() === null ? '' : $this->words->cart($this->progress->of($this->shopper->cart()), $applied, $count),
            'show_applied' => $applied,
            'show_bars' => ($attributes['showBars'] ?? true) !== false,
            'count' => $count,
        ]);
    }
}
