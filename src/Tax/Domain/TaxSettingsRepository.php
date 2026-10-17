<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

interface TaxSettingsRepository
{
    /** The saved settings, or the defaults when nothing valid was saved. */
    public function load(): TaxSettings;

    public function save(TaxSettings $settings): void;
}
