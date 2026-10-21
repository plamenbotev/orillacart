<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Settings;

use OrillaCart\Settings\Domain\CurrencySettings;
use OrillaCart\Settings\Domain\CurrencySettingsRepository;

final class MemoryCurrencySettings implements CurrencySettingsRepository
{
    public CurrencySettings $settings;

    public function __construct()
    {
        $this->settings = new CurrencySettings();
    }

    public function load(): CurrencySettings
    {
        return $this->settings;
    }

    public function save(CurrencySettings $settings): void
    {
        $this->settings = $settings;
    }
}
