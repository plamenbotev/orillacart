<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Storefront;

use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Application\StoreNoticeTranslations;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Tests\Fixtures\FakeLanguages;
use OrillaCart\Tests\Fixtures\I18n\FakeContentLanguages;
use OrillaCart\Tests\Fixtures\I18n\FakeTranslationService;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;
use OrillaCart\Tests\Fixtures\Translations\InMemoryTranslations;
use OrillaCart\Translations\Application\ContentTranslations;

final class InMemoryStorefrontSettings implements StorefrontSettingsRepository
{
    public StorefrontSettings $settings;

    public function __construct()
    {
        $this->settings = new StorefrontSettings();
    }

    public function load(): StorefrontSettings
    {
        return $this->settings;
    }

    public function save(StorefrontSettings $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * The settings' service over these, with the store notice's translations in memory: a site in English and
     * German unless other languages are given, shown in the locale of $languages.
     */
    public function service(?FakeContentLanguages $content = null, ?FakeLanguages $languages = null, ?InMemoryTranslations $translations = null): StorefrontSettingsService
    {
        $translations ??= new InMemoryTranslations();
        $translations->add(new StoreNoticeTranslations());

        return new StorefrontSettingsService($this, new ContentTranslations($translations, $translations, $content ?? new FakeContentLanguages(), $languages ?? new FakeLanguages()), new ImmediateTransactions(), FakeTranslationService::none());
    }
}
