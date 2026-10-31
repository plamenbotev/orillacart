<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Shared\Domain\Text;
use OrillaCart\Storefront\Domain\StorefrontSettingsRepository;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** The store notice's words for the translation service: its text and its link's. */
final readonly class StoreNoticePackages implements TranslationPackageSource
{
    public const string KIND = 'store_notice';

    public function __construct(private StorefrontSettingsRepository $settings) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart store notice';
    }

    public function package(int $id): ?TranslationPackage
    {
        $notice = $this->settings->load()->notice;
        if ($id !== StoreNoticeTranslations::ID || trim($notice->text) === '') {
            return null;
        }
        $type = StoreNoticeTranslations::TYPE;

        return TranslationPackage::of(self::KIND, $id, 'Store notice', [
            new PackageString($type, $id, 'text', $notice->text, Text::of('Text'), true),
            new PackageString($type, $id, 'link_label', $notice->linkLabel, Text::of('Link')),
        ]);
    }

    public function ids(): iterable
    {
        return trim($this->settings->load()->notice->text) === '' ? [] : [StoreNoticeTranslations::ID];
    }
}
