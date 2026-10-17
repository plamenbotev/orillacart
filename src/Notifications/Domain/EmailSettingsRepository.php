<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

interface EmailSettingsRepository
{
    public function load(): EmailSettings;

    public function save(EmailSettings $settings): void;
}
