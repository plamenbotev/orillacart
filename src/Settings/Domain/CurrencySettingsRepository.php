<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

interface CurrencySettingsRepository
{
    public function load(): CurrencySettings;

    public function save(CurrencySettings $settings): void;
}
