<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress;

use OrillaCart\Settings\Application\KeptHistory;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\WordPress\ScheduledTask;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every day: each module forgets the history older than the shop keeps it (the "Keep history for" setting), such
 * as stock movements, past prices and the records of carts left at checkout. Tables that only grew before
 * (design 55); orders, and what the reports count, are not history and stay.
 */
final readonly class ForgetOldHistory implements ScheduledTask
{
    /** @param iterable<KeptHistory> $histories */
    public function __construct(
        private SettingsService $settings,
        private ClockInterface $clock,
        #[AutowireIterator(KeptHistory::TAG)]
        private iterable $histories,
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
        foreach ($this->histories as $history) {
            $history->forgetBefore($before);
        }
    }
}
