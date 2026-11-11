<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Extension\Storefront\TemplateFailed;
use OrillaCart\Platform\Twig\AddOnTemplates;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\AddOnSiteBlocks;
use OrillaCart\Tests\Fixtures\Extension\TestAddOn;

/**
 * Extension API 24 in a real WordPress: a folder of templates the shop does not trust (the test add-on names it in
 * ORILLACART_TEST_UNTRUSTED_TEMPLATES) overrides the shop's templates and renders in the sandbox; add-ons' filters
 * reach every template; add-ons render the storefront's templates; and an add-on's site block is registered with
 * its settings.
 */
final class AddOnTemplatesTest extends RestTestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        putenv('ORILLACART_TEST_UNTRUSTED_TEMPLATES');
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testAnUntrustedFolderOverridesTheShopsTemplatesInTheSandbox(): void
    {
        $this->dir = sys_get_temp_dir() . '/oc-untrusted-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storefront', 0o755, true);
        mkdir($this->dir . '/sections', 0o755, true);
        file_put_contents($this->dir . '/storefront/_pagination.html.twig', '<nav class="theirs">{{ "next"|shout(2) }} {{ "a"|upper }}</nav>');
        file_put_contents($this->dir . '/sections/hero.twig', '<h2>{{ heading }}</h2>');
        file_put_contents($this->dir . '/sections/escape.twig', '{{ constant("DB_PASSWORD") }}');
        file_put_contents($this->dir . '/sections/loop.twig', '{% for i in 1..100000 %}{% for j in 1..1000 %}.{% endfor %}{% endfor %}');
        putenv('ORILLACART_TEST_UNTRUSTED_TEMPLATES=' . $this->dir);

        $templates = TestAddOn::$registry?->templates();
        self::assertInstanceOf(AddOnTemplates::class, $templates, 'what the shop gives add-ons');
        self::assertTrue($templates->exists('sections/hero.twig'));
        self::assertSame('<h2>Fresh &amp; warm</h2>', $templates->render('sections/hero.twig', ['heading' => 'Fresh & warm']), 'escaped as any template');
        self::assertSame('<nav class="theirs">NEXT!! A</nav>', $templates->render('storefront/_pagination.html.twig', ['page' => 1, 'pages' => 1]), 'their override, with the add-on\'s filter and the shop\'s');

        foreach (['sections/escape.twig' => 'constant', 'sections/loop.twig' => 'budget'] as $name => $why) {
            try {
                $templates->render($name);
                self::fail("{$name} rendered");
            } catch (TemplateFailed $e) {
                self::assertNotSame('', $e->getMessage(), $why);
            }
        }
        $this->expectException(TemplateFailed::class);
        $templates->render('sections/nothing.twig');
    }

    public function testAnAddOnsSiteBlockIsRegisteredWithItsSettingsAndRendersWhatTheyTake(): void
    {
        $block = \WP_Block_Type_Registry::get_instance()->get_registered('orillacart-addon/test-banner');
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertSame('Test banner', $block->title);
        $attributes = array_keys((array) $block->attributes);
        self::assertSame([], array_diff(['heading', 'times', 'loud', 'tone'], $attributes));
        self::assertNotContains('key', $attributes, 'a secret is no block\'s setting');

        $html = render_block(['blockName' => 'orillacart-addon/test-banner', 'attrs' => ['heading' => 'Hi', 'times' => '3', 'loud' => 'yes', 'tone' => 'hot', 'evil' => 'x'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
        self::assertStringContainsString('class="test-banner"', $html);
        self::assertStringContainsString(esc_html((string) wp_json_encode(['heading' => 'Hi', 'times' => 3])), $html, 'what the settings take; a choice not offered and a word for a switch are left out');
        self::assertSame('orillacart-addon/test-banner', AddOnSiteBlocks::name(new class implements \OrillaCart\Extension\Storefront\SiteBlock {
            public function id(): string
            {
                return 'test_banner';
            }

            public function title(): string
            {
                return '';
            }

            public function description(): string
            {
                return '';
            }

            public function settings(): array
            {
                return [];
            }

            public function render(array $settings): string
            {
                return '';
            }
        }));
    }
}
