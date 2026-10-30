<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\WordPressLanguages;
use PHPUnit\Framework\TestCase;

/**
 * Languages::in() on the real WordPress: the locale is switched for the work, so __() and wp_date() answer in it,
 * and is put back after, whether the work returned or threw. wp-env has no German files: German is made an
 * available language for the test, as a downloaded language pack makes it.
 */
final class WordPressLanguagesTest extends TestCase
{
    private \WP_Locale_Switcher $switcher;
    private \Closure $german;
    private \Closure $gettext;

    protected function setUp(): void
    {
        $this->german = static fn(array $languages): array => array_values(array_unique([...array_filter($languages, is_string(...)), 'de_DE']));
        add_filter('get_available_languages', $this->german);
        // The switcher learns the available languages when it is made: a fresh one knows German.
        $switcher = $GLOBALS['wp_locale_switcher'];
        self::assertInstanceOf(\WP_Locale_Switcher::class, $switcher);
        $this->switcher = $switcher;
        $GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher();
        $GLOBALS['wp_locale_switcher']->init();
        // The plugin's German .mo is not built: what it would say, by the locale WordPress loads translations for.
        $this->gettext = static fn(string $translation, string $text): string => $text === 'Cart' && get_locale() === 'de_DE' ? 'Warenkorb' : $translation;
        add_filter('gettext_orillacart', $this->gettext, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('gettext_orillacart', $this->gettext);
        $GLOBALS['wp_locale_switcher'] = $this->switcher;
        remove_filter('get_available_languages', $this->german);
    }

    public function testTheWorkRunsInTheLocaleAndTheCallersComesBack(): void
    {
        $before = [get_locale(), determine_locale()];
        self::assertSame('en_US', get_locale());

        $seen = new WordPressLanguages()->in('de_DE', static fn(): array => [get_locale(), determine_locale(), is_locale_switched(), __('Cart', 'orillacart')]);

        self::assertSame(['de_DE', 'de_DE', true, 'Warenkorb'], $seen);
        self::assertSame($before, [get_locale(), determine_locale()]);
        self::assertFalse(is_locale_switched());
        self::assertSame('Cart', __('Cart', 'orillacart'));
    }

    public function testALocaleWordPressDoesNotHaveIsStillTheOneDetermined(): void
    {
        $seen = new WordPressLanguages()->in('bg_BG', static fn(): array => [get_locale(), determine_locale(), is_locale_switched()]);

        self::assertSame(['en_US', 'bg_BG', false], $seen, 'the shop\'s own translations go by determine_locale()');
        self::assertSame('en_US', determine_locale());
    }

    public function testTheLocaleComesBackWhenTheWorkThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            new WordPressLanguages()->in('de_DE', static function (): never {
                throw new \RuntimeException('broke');
            });
        } finally {
            self::assertSame(['en_US', 'en_US', false], [get_locale(), determine_locale(), is_locale_switched()]);
        }
    }


    public function testASwitchInsideIsUndoneAlone(): void
    {
        $languages = new WordPressLanguages();
        $seen = $languages->in('de_DE', static function () use ($languages): array {
            $inner = $languages->in('bg_BG', static fn(): string => determine_locale());

            return [$inner, get_locale(), determine_locale()];
        });

        self::assertSame(['bg_BG', 'de_DE', 'de_DE'], $seen);
        self::assertSame('en_US', determine_locale());
    }
}
