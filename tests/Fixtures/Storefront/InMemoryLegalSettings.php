<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Storefront;

use OrillaCart\Storefront\Domain\LegalSettings;
use OrillaCart\Storefront\Domain\LegalSettingsRepository;

final class InMemoryLegalSettings implements LegalSettingsRepository
{
    /** @param list<int> $pages the ids that are pages */
    public function __construct(
        public LegalSettings $settings = new LegalSettings(),
        public array $pages = [],
    ) {}

    public function load(): LegalSettings
    {
        return $this->settings;
    }

    public function save(LegalSettings $settings): void
    {
        $this->settings = $settings;
    }

    public function isPage(int $id): bool
    {
        return \in_array($id, $this->pages, true);
    }
}
