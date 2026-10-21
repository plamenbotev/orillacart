<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Settings;

use OrillaCart\Settings\Application\CurrencyChoice;

final class ChosenCurrency implements CurrencyChoice
{
    public ?string $currency = null;

    public function chosen(): ?string
    {
        return $this->currency;
    }
}
