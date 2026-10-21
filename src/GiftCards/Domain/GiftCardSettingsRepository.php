<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

interface GiftCardSettingsRepository
{
    public function load(): GiftCardSettings;

    public function save(GiftCardSettings $settings): void;
}
