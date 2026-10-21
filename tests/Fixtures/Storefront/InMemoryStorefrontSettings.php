<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Storefront;

use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;

final class InMemoryStorefrontSettings implements StorefrontSettingsRepository
{
    public StorefrontSettings $settings;

    public function __construct()
    {
        $this->settings = new StorefrontSettings();
    }

    public function load(): StorefrontSettings
    {
        return $this->settings;
    }

    public function save(StorefrontSettings $settings): void
    {
        $this->settings = $settings;
    }
}
