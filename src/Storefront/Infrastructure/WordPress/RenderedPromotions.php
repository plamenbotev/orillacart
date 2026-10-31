<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\PromotionProgress;
use OrillaCart\Storefront\Application\PromotionsHtml;

final readonly class RenderedPromotions implements PromotionsHtml
{
    /** The most promotions still to get the script is given to choose from: no block shows more. */
    public const int MOST = 10;

    public function __construct(
        private PromotionProgress $progress,
        private PromotionWords $words,
    ) {}

    public function render(CartOwner $owner, bool $applied = true, int $count = self::MOST): string
    {
        return $this->words->cart($this->progress->of($owner), $applied, min($count, self::MOST));
    }
}
