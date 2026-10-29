<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\I18n\FakeContentLanguages;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Infrastructure\WordPress\WpmlStringPackages;
use PHPUnit\Framework\TestCase;

/**
 * WPML String Translation is commercial and not in wp-env: its documented string package hooks stand in for it
 * (https://wpml.org/documentation/support/string-package-translation/), answered as WPML answers them.
 */
final class WpmlStringPackagesTest extends TestCase
{
    private const array HOOKS = ['wpml_start_string_package_registration', 'wpml_register_string', 'wpml_delete_unused_package_strings', 'wpml_delete_package', 'wpml_switch_language', 'wpml_current_language', 'wpml_translate_string'];

    /** @var list<array{string, list<mixed>}> */
    private array $calls = [];
    private string $language = 'en';
    /** @var array<string, array<string, string>> WPML's translations by string name and language */
    private array $translations = [];

    protected function setUp(): void
    {
        foreach (['wpml_start_string_package_registration' => 1, 'wpml_register_string' => 5, 'wpml_delete_unused_package_strings' => 1, 'wpml_delete_package' => 2] as $hook => $arguments) {
            add_action($hook, function (mixed ...$args) use ($hook): void {
                $this->calls[] = [$hook, array_values($args)];
            }, 10, $arguments);
        }
        add_action('wpml_switch_language', function (mixed $code): void {
            $this->language = \is_string($code) ? $code : 'en';
        });
        add_filter('wpml_current_language', fn(): string => $this->language);
        // The string itself where it has no translation, as WPML answers.
        add_filter('wpml_translate_string', fn(mixed $value, mixed $name): mixed => \is_string($name) ? ($this->translations[$name][$this->language] ?? $value) : $value, 10, 2);
    }

    protected function tearDown(): void
    {
        foreach (self::HOOKS as $hook) {
            remove_all_filters($hook);
        }
    }

    public function testAPackageIsRegisteredWithItsStringsAndUnusedOnesDropped(): void
    {
        $this->wpml()->register($this->package(), 'OrillaCart carrier');

        $package = ['kind' => 'OrillaCart carrier', 'name' => 'carrier-3', 'title' => 'Pickup'];
        self::assertSame([
            ['wpml_start_string_package_registration', [$package]],
            ['wpml_register_string', ['Pickup', md5('carrier:3:name'), $package, 'Name', 'LINE']],
            ['wpml_register_string', ['Main St 1', md5('carrier:3:pickup.shop.address'), $package, 'Pickup place The shop: address', 'AREA']],
            ['wpml_delete_unused_package_strings', [$package]],
        ], $this->calls);
    }

    public function testTheFinishedTranslationsAreReadInEachLanguageAndTheLanguageComesBack(): void
    {
        $this->translations = [md5('carrier:3:name') => ['de' => 'Abholung', 'fr' => 'Pickup']];

        $found = $this->wpml()->translated($this->package(), 'OrillaCart carrier');

        self::assertSame(['carrier:3:name' => ['de_DE' => 'Abholung']], $found, 'a "translation" that is the word itself is none');
        self::assertSame('en', $this->language);
    }

    public function testAPackageIsDeletedByItsNameAndKind(): void
    {
        $this->wpml()->remove('carrier', 3, 'OrillaCart carrier');

        self::assertSame([['wpml_delete_package', ['carrier-3', 'OrillaCart carrier']]], $this->calls);
    }

    private function wpml(): WpmlStringPackages
    {
        return new WpmlStringPackages(new FakeContentLanguages([new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('fr', 'fr_FR', 'Français')]), new GettextTranslations());
    }

    private function package(): TranslationPackage
    {
        return TranslationPackage::of('carrier', 3, 'Pickup', [
            new PackageString('carrier', 3, 'name', 'Pickup', Text::of('Name')),
            new PackageString('carrier', 3, 'pickup.shop.address', 'Main St 1', Text::of('Pickup place %s: address', 'The shop'), true),
            new PackageString('carrier', 3, 'pickup.shop.hours', '', Text::of('Pickup place %s: opening hours', 'The shop'), true),
        ]);
    }
}
