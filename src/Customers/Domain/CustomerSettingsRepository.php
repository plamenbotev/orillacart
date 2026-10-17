<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

interface CustomerSettingsRepository
{
    public function load(): CustomerSettings;

    public function save(CustomerSettings $settings): void;
}
