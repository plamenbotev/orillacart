<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Newsletter;

use OrillaCart\Newsletter\Domain\NewsletterSettings;
use OrillaCart\Newsletter\Domain\NewsletterSettingsRepository;

final class InMemoryNewsletterSettings implements NewsletterSettingsRepository
{
    public function __construct(public NewsletterSettings $settings = new NewsletterSettings()) {}

    public function load(): NewsletterSettings
    {
        return $this->settings;
    }

    public function save(NewsletterSettings $settings): void
    {
        $this->settings = $settings;
    }
}
