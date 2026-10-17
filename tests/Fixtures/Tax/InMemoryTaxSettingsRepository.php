<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tax\Domain\TaxSettingsRepository;

final class InMemoryTaxSettingsRepository implements TaxSettingsRepository
{
    public function __construct(public ?TaxSettings $saved = null) {}

    public function load(): TaxSettings
    {
        return $this->saved ?? TaxSettings::defaults();
    }

    public function save(TaxSettings $settings): void
    {
        $this->saved = $settings;
    }
}
