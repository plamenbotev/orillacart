<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

interface SettingsRepository
{
    /** The saved settings, or the defaults when nothing valid was saved. */
    public function load(): StoreSettings;

    public function save(StoreSettings $settings): void;
}
