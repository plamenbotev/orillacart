<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

interface StorefrontSettingsRepository
{
    public function load(): StorefrontSettings;

    public function save(StorefrontSettings $settings): void;
}
