<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\FreeShippingProgress;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Storefront\Application\FreeShippingHtml;

final readonly class RenderedFreeShipping implements FreeShippingHtml
{
    public function __construct(
        private FreeShippingProgress $progress,
        private TemplateRenderer $templates,
    ) {}

    public function render(CartOwner $owner): string
    {
        $progress = $this->progress->of($owner);
        if ($progress === null) {
            return '';
        }
        $percent = 100;
        if ($progress->spent !== null && $progress->threshold !== null && !Money::of($progress->threshold)->isZero()) {
            // Whole percents for the bar's width, never full before it is reached.
            $percent = min(99, max(0, (int) floor((float) $progress->spent / (float) $progress->threshold * 100)));
        }

        return trim($this->templates->render('storefront/_free_shipping.html.twig', [
            'progress' => $progress,
            'percent' => $percent,
        ]));
    }
}
