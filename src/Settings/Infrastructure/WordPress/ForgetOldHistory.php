<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Packages\Application\RunLog;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Psr\Clock\ClockInterface;

/**
 * Every day: deletes the history older than the shop keeps it (the "Keep history for" setting): stock movements,
 * past prices (each item's current one stays) and the records of carts left at checkout. Tables that only grew
 * before (design 55); orders, and what the reports count, are not history and stay.
 */
final readonly class ForgetOldHistory implements ScheduledTask
{
    public function __construct(
        private SettingsService $settings,
        private StockService $stock,
        private PriceHistory $prices,
        private AbandonedCarts $abandoned,
        private ClockInterface $clock,
        private ?RunLog $runs = null,
    ) {}

    public static function hook(): string
    {
        return 'orillacart_forget_old_history';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $before = $this->clock->now()->sub(new \DateInterval(\sprintf('P%dD', $this->settings->current()->historyDays)));
        $this->stock->forgetMovementsBefore($before);
        $this->prices->forgetBefore($before);
        $this->abandoned->forgetBefore($before);
        $this->runs?->forgetBefore($before);
    }
}
