<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Retention;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Settings\Application\KeptHistory;

/** The records of carts left at checkout. */
final readonly class ForgetLeftCarts implements KeptHistory
{
    public function __construct(private AbandonedCarts $abandoned) {}

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->abandoned->forgetBefore($before);
    }
}
