<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Storefront\Application\Dto\StorefrontSettingsView;
use OrillaCart\Storefront\Application\Dto\UpdateStorefrontSettings;
use OrillaCart\Storefront\Domain\SharingTags;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Storefront\Domain\StoreNotice;
use OrillaCart\Storefront\Domain\WishlistPolicy;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Application\TranslationPackages;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

final readonly class StorefrontSettingsService
{
    public function __construct(
        private StorefrontSettingsRepository $settings,
        private ContentTranslations $translations,
        private Transactions $transactions,
        private TranslationPackages $packages,
    ) {}

    public function current(): StorefrontSettings
    {
        return $this->settings->load();
    }

    public function settings(): StorefrontSettingsView
    {
        return StorefrontSettingsView::of($this->settings->load(), $this->noticeTranslations());
    }

    /**
     * @throws StorefrontRuleViolation for a store notice too long, or a link that is not an address
     * @throws TranslationRuleViolation
     */
    public function update(UpdateStorefrontSettings $command): StorefrontSettingsView
    {
        $texts = $command->noticeTranslations === null ? [] : $this->translations->check(StoreNoticeTranslations::TYPE, $command->noticeTranslations);
        $notice = new StoreNotice($command->notice, trim($command->noticeText), trim($command->noticeLink), trim($command->noticeLinkLabel), $command->noticeDismissible);
        $settings = new StorefrontSettings($command->structuredData, SharingTags::from($command->sharingTags), WishlistPolicy::from($command->wishlist), $command->recentlyViewed, $command->dataLayer, $command->hideSingleValueFilters, $command->listUntranslated, $notice);
        // The settings and the notice's translations together, or neither.
        $this->transactions->run(function () use ($settings, $texts): void {
            $this->settings->save($settings);
            $this->translations->write(StoreNoticeTranslations::TYPE, StoreNoticeTranslations::ID, $texts);
        });
        $this->packages->changed(StoreNoticePackages::KIND, StoreNoticeTranslations::ID);

        return StorefrontSettingsView::of($settings, $this->noticeTranslations());
    }

    /**
     * The store notice as shoppers read it, in the language shown: its translation where it has one. Null while
     * off or without text.
     */
    public function shownNotice(): ?StoreNotice
    {
        $notice = $this->settings->load()->notice;
        if (!$notice->visible()) {
            return null;
        }
        $shown = $this->translations->shown(StoreNoticeTranslations::TYPE, [StoreNoticeTranslations::ID])[StoreNoticeTranslations::ID] ?? [];
        $text = trim($shown['text'] ?? '');
        $label = trim($shown['link_label'] ?? '');

        return new StoreNotice(true, $text !== '' ? $text : $notice->text, $notice->link, $label !== '' && $notice->link !== '' ? $label : $notice->linkLabel, $notice->dismissible);
    }

    /**
     * @return array<string, array<string, string>> by field and locale
     */
    private function noticeTranslations(): array
    {
        return $this->translations->of(StoreNoticeTranslations::TYPE, [StoreNoticeTranslations::ID])[StoreNoticeTranslations::ID] ?? [];
    }
}
