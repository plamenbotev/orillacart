<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\GiftCards;

use OrillaCart\GiftCards\Domain\GiftCardSettings;
use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;

final class MemoryGiftCardSettings implements GiftCardSettingsRepository
{
    public GiftCardSettings $settings;

    public function __construct()
    {
        $this->settings = new GiftCardSettings();
    }

    public function load(): GiftCardSettings
    {
        return $this->settings;
    }

    public function save(GiftCardSettings $settings): void
    {
        $this->settings = $settings;
    }
}
