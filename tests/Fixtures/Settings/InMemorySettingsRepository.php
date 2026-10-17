<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Settings;

use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;

final class InMemorySettingsRepository implements SettingsRepository
{
    public function __construct(public ?StoreSettings $saved = null) {}

    public function load(): StoreSettings
    {
        return $this->saved ?? StoreSettings::defaults();
    }

    public function save(StoreSettings $settings): void
    {
        $this->saved = $settings;
    }
}
