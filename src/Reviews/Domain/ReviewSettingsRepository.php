<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Domain;

interface ReviewSettingsRepository
{
    public function load(): ReviewSettings;

    public function save(ReviewSettings $settings): void;
}
