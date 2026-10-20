<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\BackInStock;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: forgets "email me when it is back" requests half a year old.
 */
final readonly class ForgetBackInStockRequests implements ScheduledTask
{
    public function __construct(private BackInStock $backInStock) {}

    public static function hook(): string
    {
        return 'orillacart_forget_back_in_stock';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->backInStock->forgetOld();
    }
}
