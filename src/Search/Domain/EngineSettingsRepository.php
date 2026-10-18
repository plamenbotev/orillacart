<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

interface EngineSettingsRepository
{
    public function load(): EngineSettings;

    public function save(EngineSettings $settings): void;
}
