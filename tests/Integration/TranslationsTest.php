<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Translations\Infrastructure\Doctrine\DbalTranslationRepository;

/**
 * The translations table on MySQL (design 51), and what the REST API reads from it. wp-env has no Polylang or
 * WPML: the site has one language, so there is nothing to translate into.
 */
final class TranslationsTest extends RestTestCase
{
    private DbalTranslationRepository $translations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translations = new DbalTranslationRepository(new EntityManagerFactory(DatabaseConfig::fromWordPress(), new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create());
        $this->translations->forget('attribute', [901, 902]);
    }

    public function testATranslationIsKeptByObjectFieldAndLocale(): void
    {
        $this->translations->replace('attribute', 901, ['name' => ['de_DE' => 'Farbe', 'fr_FR' => 'Couleur']]);
        $this->translations->replace('attribute', 902, ['name' => ['de_DE' => 'Größe']]);

        self::assertSame([901 => 'Farbe', 902 => 'Größe'], $this->translations->values('attribute', [901, 902, 903], 'name', 'de_DE'));
        self::assertSame([], $this->translations->values('attribute_option', [901], 'name', 'de_DE'), 'another type\'s 901');
        $this->translations->replace('attribute', 901, ['pickup.shop.hours' => ['de_DE' => 'Mo–Fr']]);
        self::assertSame([901 => ['name' => 'Farbe', 'pickup.shop.hours' => 'Mo–Fr'], 902 => ['name' => 'Größe']], $this->translations->inLocale('attribute', [901, 902], 'de_DE'), 'every field in one language');

        // A field sent replaces its languages: French goes, an empty value removes German.
        $this->translations->replace('attribute', 901, ['name' => ['de_DE' => 'Farbton', 'it_IT' => ''], 'pickup.shop.hours' => []]);
        self::assertSame([901 => ['name' => ['de_DE' => 'Farbton']], 902 => ['name' => ['de_DE' => 'Größe']]], $this->translations->all('attribute', [901, 902]));

        $this->translations->forget('attribute', [901]);
        self::assertSame([902], array_keys($this->translations->all('attribute', [901, 902])));
    }

    public function testTheAdminScreensReadTheTranslationsAndTheLanguages(): void
    {
        $this->translations->replace('attribute', 901, ['name' => ['de_DE' => 'Farbe']]);
        $this->actAs('administrator');

        $read = $this->request('GET', '/translations?type=attribute&ids=901,902');
        self::assertSame(200, $read->get_status());
        self::assertSame(['901' => ['name' => ['de_DE' => 'Farbe']]], $this->data($read));
        self::assertSame(422, $this->request('GET', '/translations?type=nothing&ids=1')->get_status());

        $languages = $this->request('GET', '/content-languages');
        self::assertSame([200, []], [$languages->get_status(), $this->data($languages)], 'one language: nothing to translate into');
    }
}
