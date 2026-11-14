<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Retention;

use OrillaCart\Catalog\Application\PriceHistory;
use OrillaCart\Settings\Application\KeptHistory;

/** Past prices; each item's current one stays. */
final readonly class ForgetPastPrices implements KeptHistory
{
    public function __construct(private PriceHistory $prices) {}

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->prices->forgetBefore($before);
    }
}
