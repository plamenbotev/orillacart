<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * The currency the shopper of this request chose; null when they chose none.
 */
interface CurrencyChoice
{
    public function chosen(): ?string;
}
