<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

interface NewsletterSettingsRepository
{
    public function load(): NewsletterSettings;

    public function save(NewsletterSettings $settings): void;
}
