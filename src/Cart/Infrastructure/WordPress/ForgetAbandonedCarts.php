<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\WordPress;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: deletes carts nobody changed for a month, and forgets the emails of carts left at checkout as long ago.
 */
final readonly class ForgetAbandonedCarts implements ScheduledTask
{
    public function __construct(
        private CartService $carts,
        private AbandonedCarts $abandoned,
    ) {}

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
        $this->abandoned->forgetOld();
    }
}
