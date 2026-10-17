<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\WordPress;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: deletes carts nobody changed for a month.
 */
final readonly class ForgetAbandonedCarts implements ScheduledTask
{
    public function __construct(private CartService $carts) {}

    public static function hook(): string
    {
        return 'orillacart_forget_abandoned_carts';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->carts->forgetAbandoned();
    }
}
