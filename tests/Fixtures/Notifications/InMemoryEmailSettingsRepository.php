<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;

final class InMemoryEmailSettingsRepository implements EmailSettingsRepository
{
    public function __construct(public EmailSettings $saved = new EmailSettings()) {}

    public function load(): EmailSettings
    {
        return $this->saved;
    }

    public function save(EmailSettings $settings): void
    {
        $this->saved = $settings;
    }
}
