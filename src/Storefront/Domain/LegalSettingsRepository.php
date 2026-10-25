<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

interface LegalSettingsRepository
{
    public function load(): LegalSettings;

    public function save(LegalSettings $settings): void;

    /** Whether the id is a page the shop can link, published or not yet. */
    public function isPage(int $id): bool;
}
