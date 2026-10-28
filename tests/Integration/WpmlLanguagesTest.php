<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Platform\WordPress\Languages\WpmlLanguages;
use PHPUnit\Framework\TestCase;

/**
 * WPML is commercial and not in wp-env: its documented filters stand in for it, answered as WPML answers them
 * (https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/).
 */
final class WpmlLanguagesTest extends TestCase
{
    /** @var array<string, \Closure> */
    private array $filters = [];
    /** @var array<int, array{string, array<string, int>}> language and translation group by post or term id */
    private array $elements = [];

    protected function setUp(): void
    {
        $this->elements = [
            10 => ['en', ['en' => 10, 'de' => 11]],
            11 => ['de', ['en' => 10, 'de' => 11]],
            12 => ['en', ['en' => 12]],
            50 => ['en', ['en' => 50, 'de' => 51]],
        ];
        $this->filter('wpml_active_languages', static fn(mixed $empty, mixed $args = null): array => [
            'en' => ['code' => 'en', 'default_locale' => 'en_US', 'native_name' => 'English', 'translated_name' => 'English'],
            'de' => ['code' => 'de', 'default_locale' => 'de_DE', 'native_name' => 'Deutsch', 'translated_name' => 'German'],
        ], 2);
        $this->filter('wpml_default_language', static fn(): string => 'en');
        $this->filter('wpml_current_language', static fn(): string => 'de');
        $this->filter('wpml_element_language_code', fn(mixed $empty, mixed $args): ?string => \is_array($args) && \is_int($args['element_id'] ?? null) ? ($this->elements[$args['element_id']][0] ?? null) : null, 2);
        $this->filter('wpml_object_id', fn(mixed $id, mixed $type, mixed $original = false, mixed $code = null): ?int => \is_int($id) && \is_string($code) ? ($this->elements[$id][1][$code] ?? null) : null, 4);
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as $hook => $callback) {
            remove_all_filters($hook);
        }
    }

    public function testItReadsTheLanguagesAndTranslationsThroughWpmlsFilters(): void
    {
        $post = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_status' => 'publish', 'post_title' => 'Shirt'], true);
        self::assertIsInt($post);
        // The stand-in answers for the post wp_insert_post made, as WPML would for its own.
        $this->elements[$post] = ['en', ['en' => $post, 'de' => 11]];
        $wpml = new WpmlLanguages();

        self::assertSame(['en', 'de'], array_map(static fn($language): string => $language->code, $wpml->languages()));
        self::assertSame('de_DE', $wpml->forLocale('de_DE')?->locale);
        self::assertSame('Deutsch', $wpml->current()?->name);
        self::assertSame('en', $wpml->default()?->code);
        self::assertSame('en', $wpml->ofPost($post));
        self::assertSame(['en' => $post, 'de' => 11], $wpml->postTranslations($post));
        self::assertSame(['en' => 50, 'de' => 51], $wpml->termTranslations(50, ProductCategoryTaxonomy::NAME));
        self::assertSame([], $wpml->postTranslations(999999), 'a post WPML does not know has no language');
        wp_delete_post($post, true);
    }

    public function testAllLanguagesOnTheAdminScreensIsNoOneLanguage(): void
    {
        remove_all_filters('wpml_current_language');
        $this->filter('wpml_current_language', static fn(): string => 'all');

        self::assertNull(new WpmlLanguages()->current());
    }

    private function filter(string $hook, \Closure $callback, int $arguments = 1): void
    {
        add_filter($hook, $callback, 10, $arguments);
        $this->filters[$hook] = $callback;
    }
}
