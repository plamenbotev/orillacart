<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The shop's settings as a file and back: what the screens save, without this site's secrets, taken in
 * section by section, and a section the shop cannot use left as it was.
 */
final class SettingsArchiveTest extends RestTestCase
{
    private const array OPTIONS = ['orillacart_reviews', 'orillacart_storefront', 'orillacart_settings', 'orillacart_search_engine'];

    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::OPTIONS as $option) {
            $this->stored[$option] = get_option($option);
        }
        $this->actAs('administrator');
    }

    protected function tearDown(): void
    {
        foreach ($this->stored as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }
        parent::tearDown();
    }

    public function testTheSettingsGoToAFileAndComeBack(): void
    {
        self::assertSame(200, $this->request('PUT', '/review-settings', ['policy' => 'buyers'], nonce: true)->get_status());
        update_option('orillacart_search_engine', ['engine' => 'database', 'host' => 'https://search.example.com', 'index' => 'shop', 'username' => 'shop', 'password' => 'sealed-secret']);
        $file = $this->data($this->request('GET', '/settings-archive'));
        self::assertSame('orillacart-settings', $file['format']);
        self::assertIsArray($file['settings']);
        self::assertSame(['policy' => 'buyers'], $file['settings']['reviews']);
        self::assertIsArray($file['settings']['search']);
        self::assertArrayNotHasKey('password', $file['settings']['search'], 'secrets stay with the site');
        self::assertArrayNotHasKey('shop_pages', $file['settings'], 'pages are this site\'s');

        self::assertSame(200, $this->request('PUT', '/review-settings', ['policy' => 'off'], nonce: true)->get_status());
        $result = $this->data($this->request('PUT', '/settings-archive', self::body($file), nonce: true));

        self::assertIsArray($result['imported']);
        self::assertContains('reviews', $result['imported']);
        self::assertSame([], $result['refused']);
        self::assertSame(['policy' => 'buyers'], get_option('orillacart_reviews'));
        $search = get_option('orillacart_search_engine');
        self::assertIsArray($search);
        self::assertArrayHasKey('password', $search, 'this site\'s secret is kept');
    }

    /**
     * @param array<mixed> $file
     *
     * @return array<string, mixed>
     */
    private static function body(array $file): array
    {
        return array_filter($file, \is_string(...), ARRAY_FILTER_USE_KEY);
    }

    public function testASectionTheShopCannotUseIsLeftAsItWas(): void
    {
        update_option('orillacart_settings', ['currency' => 'EUR', 'weight_unit' => 'kg', 'dimension_unit' => 'cm']);
        $before = get_option('orillacart_settings');
        $file = $this->data($this->request('GET', '/settings-archive'));
        self::assertIsArray($file['settings']);
        self::assertIsArray($file['settings']['shop']);
        $file['settings']['shop']['currency'] = 'NOT A CURRENCY';
        $file['settings']['nonsense'] = ['a' => 1];
        // Reading the bad settings back, the shop logs that it falls back to its defaults.
        $this->expectOutputRegex('/not a currency code/');

        $result = $this->data($this->request('PUT', '/settings-archive', self::body($file), nonce: true));

        self::assertIsArray($result['refused']);
        self::assertArrayHasKey('shop', $result['refused']);
        self::assertIsString($result['refused']['shop']);
        self::assertStringContainsString('currency', $result['refused']['shop']);
        self::assertArrayHasKey('nonsense', $result['refused']);
        self::assertSame($before, get_option('orillacart_settings'));
        self::assertSame(['file' => 'This is not a file of the shop\'s settings.'], $this->data($this->request('PUT', '/settings-archive', ['a' => 1], nonce: true))['refused']);
    }
}
