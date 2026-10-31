<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Storefront;

use OrillaCart\Storefront\Application\Dto\UpdateStorefrontSettings;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;
use OrillaCart\Tests\Fixtures\FakeLanguages;
use OrillaCart\Tests\Fixtures\Storefront\InMemoryStorefrontSettings;
use OrillaCart\Translations\Domain\TranslationRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The store notice in the storefront's settings: on a site in English and German, read in the language shown.
 */
final class StorefrontSettingsServiceTest extends TestCase
{
    private InMemoryStorefrontSettings $settings;
    private FakeLanguages $languages;
    private StorefrontSettingsService $service;

    protected function setUp(): void
    {
        $this->settings = new InMemoryStorefrontSettings();
        $this->languages = new FakeLanguages();
        $this->service = $this->settings->service(languages: $this->languages);
    }

    public function testTheNoticeIsSavedWithItsTranslationsAndShownInTheLanguageShown(): void
    {
        $view = $this->service->update(new UpdateStorefrontSettings(
            notice: true,
            noticeText: '  Free delivery this week ',
            noticeLink: '/sale',
            noticeLinkLabel: 'See the offers',
            noticeDismissible: false,
            noticeTranslations: ['text' => ['de_DE' => 'Kostenlose Lieferung diese Woche'], 'link_label' => ['de_DE' => '']],
        ));

        self::assertSame(['Free delivery this week', '/sale', 'See the offers', false], [$view->noticeText, $view->noticeLink, $view->noticeLinkLabel, $view->noticeDismissible], 'trimmed');
        self::assertSame(['text' => ['de_DE' => 'Kostenlose Lieferung diese Woche']], $view->noticeTranslations, 'an empty translation is none');
        $english = $this->service->shownNotice();
        self::assertNotNull($english);
        self::assertSame('Free delivery this week', $english->text);

        $this->languages->locale = 'de_DE';
        $shown = $this->service->shownNotice();
        self::assertNotNull($shown);
        self::assertSame(['Kostenlose Lieferung diese Woche', 'See the offers'], [$shown->text, $shown->linkLabel], 'the link\'s own words where it has no translation');
    }

    public function testTranslationsAreKeptWhenNoneAreSent(): void
    {
        $this->service->update(new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeTranslations: ['text' => ['de_DE' => 'Ausverkauf']]));
        $view = $this->service->update(new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', wishlist: 'off'));

        self::assertSame(['text' => ['de_DE' => 'Ausverkauf']], $view->noticeTranslations);
        self::assertSame(['text' => ['de_DE' => 'Ausverkauf']], $this->service->settings()->noticeTranslations);
    }

    public function testANoticeOffOrWithoutTextIsShownNowhere(): void
    {
        self::assertNull($this->service->shownNotice(), 'none yet');
        $this->service->update(new UpdateStorefrontSettings(notice: false, noticeText: 'Sale'));
        self::assertNull($this->service->shownNotice(), 'off');
        $this->service->update(new UpdateStorefrontSettings(notice: true, noticeText: '   '));
        self::assertNull($this->service->shownNotice(), 'without text');
    }

    /**
     * @return iterable<string, array{UpdateStorefrontSettings}>
     */
    public static function refused(): iterable
    {
        yield 'a script for a link' => [new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeLink: 'javascript:alert(1)')];
        yield 'another site without its scheme' => [new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeLink: '//example.com/sale')];
        yield 'an address with spaces' => [new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeLink: 'https://example.com/a sale')];
        yield 'words for a link without one' => [new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeLinkLabel: 'See more')];
        yield 'a text too long' => [new UpdateStorefrontSettings(notice: true, noticeText: str_repeat('a', 301))];
    }

    #[DataProvider('refused')]
    public function testANoticeTheShopWouldNotShowIsRefusedAndNothingChanges(UpdateStorefrontSettings $command): void
    {
        try {
            $this->service->update($command);
            self::fail('The notice was saved.');
        } catch (StorefrontRuleViolation) {
            self::assertFalse($this->settings->load()->notice->shown);
        }
    }

    public function testATranslationTooLongIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->expectException(TranslationRuleViolation::class);
        try {
            $this->service->update(new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeTranslations: ['text' => ['de_DE' => str_repeat('a', 301)]]));
        } finally {
            self::assertFalse($this->settings->load()->notice->shown);
        }
    }

    public function testLinksToTheWebOrToThisSite(): void
    {
        foreach (['https://example.com/sale?week=1', 'http://example.com', '/sale', '/'] as $link) {
            self::assertSame($link, $this->service->update(new UpdateStorefrontSettings(notice: true, noticeText: 'Sale', noticeLink: $link))->noticeLink);
        }
    }
}
