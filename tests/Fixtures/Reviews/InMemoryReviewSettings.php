<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Reviews;

use OrillaCart\Reviews\Domain\ReviewSettings;
use OrillaCart\Reviews\Domain\ReviewSettingsRepository;

final class InMemoryReviewSettings implements ReviewSettingsRepository
{
    public ReviewSettings $settings;

    public function __construct()
    {
        $this->settings = new ReviewSettings();
    }

    public function load(): ReviewSettings
    {
        return $this->settings;
    }

    public function save(ReviewSettings $settings): void
    {
        $this->settings = $settings;
    }
}
