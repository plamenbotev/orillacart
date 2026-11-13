<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Extension\Setting;
use OrillaCart\Extension\Storefront\BlockPlace;
use OrillaCart\Packages\Application\Adapters\ScriptedBlockPattern;
use OrillaCart\Packages\Application\Adapters\ScriptedProvider;
use OrillaCart\Packages\Application\Adapters\ScriptedSiteBlock;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\ScriptedRegistrant;
use OrillaCart\Platform\WordPress\Storefront\AddOnBlockPatterns;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\AddOnSiteBlocks;

/**
 * Design 73: blocks for complex layouts. A site block's own kinds of settings, checked as the block gets them; blocks
 * inside it; where it is; its scripts and stylesheets where it is placed; its own editing screen in a frame; and an
 * extension's block patterns, which hold nothing that runs.
 */
final class RichSiteBlocksTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "blocks" {
          name: "Blocks"
          needs: { assets: [serve, scripts] }
        }
        site_block "tabs" {
          title: "Tabs", description: "Tabs of blocks", inner: true
          assets: ["tabs.js", "tabs.css"], editor: "tabs-editor.js"
          settings: {
            accent: color { label: "Accent" }, more: link { label: "More" }, hero: image { label: "Hero" },
            pick: product { label: "Product" }, group: category { label: "Category" }, intro: rich_text { label: "Intro" },
            tabs: list { label: "Tabs", fields: { title: text { label: "Title" }, icon: image { label: "Icon" } } }
          }
          fn render(settings, context) {
            return "<div class='tabs' data-post='" ~ str(context.post_id ?? 0) ~ "'>" ~ context.content ~ "</div>"
          }
        }
        pattern "hero" { title: "Hero", description: "A big start", categories: ["banner"], file: "patterns/hero.html" }
        pattern "bad" { title: "Bad", file: "patterns/bad.html" }
        OCS;

    private PackageService $packages;
    private ScriptedRegistrant $registrant;
    /** @var list<int> what a test made in WordPress, removed after it */
    private array $posts = [];
    /** @var list<int> */
    private array $terms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $container = self::kernel()->container();
        $packages = $container->get(PackageService::class);
        \assert($packages instanceof PackageService);
        $this->packages = $packages;
        $registrant = $container->get(ScriptedRegistrant::class);
        \assert($registrant instanceof ScriptedRegistrant);
        $this->registrant = $registrant;
        $this->request('DELETE', '/extensions/blocks', nonce: true);
        $installed = $this->request('POST', '/extensions', ['id' => 'blocks', 'name' => 'Blocks', 'files' => [
            'main.ocs' => self::SCRIPT,
            'assets/tabs.js' => 'document.querySelectorAll(".tabs")',
            'assets/tabs.css' => '.tabs{display:grid}',
            'assets/tabs-editor.js' => 'window.orillacart.block.get()',
            'patterns/hero.html' => '<!-- wp:paragraph --><p onclick="go()">Hello</p><!-- /wp:paragraph -->',
            'patterns/bad.html' => '<!-- wp:paragraph {"content":"<img src=x onerror=go()>"} --><p>Hi</p><!-- /wp:paragraph -->',
        ]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        $enabled = $this->request('POST', '/extensions/blocks/enable', ['grant' => ['assets', 'assets:scripts']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $id) {
            get_post_type($id) === 'attachment' ? wp_delete_attachment($id, true) : wp_delete_post($id, true);
        }
        foreach ($this->terms as $id) {
            wp_delete_term($id, ProductCategoryTaxonomy::NAME);
        }
        $this->posts = $this->terms = [];
        $this->request('DELETE', '/extensions/blocks', nonce: true);
        parent::tearDown();
    }

    public function testABlocksOwnKindsOfSettingsAreCheckedAsTheBlockGetsThem(): void
    {
        $tabs = $this->tabs();
        self::assertSame(
            [['accent', 'color'], ['more', 'link'], ['hero', 'image'], ['pick', 'product'], ['group', 'category'], ['intro', 'rich_text'], ['tabs', 'list']],
            array_map(static fn(Setting $s): array => [$s->key, $s->type], $tabs->settings()),
        );
        self::assertSame([['title', 'text'], ['icon', 'image']], array_map(static fn(Setting $s): array => [$s->key, $s->type], $tabs->settings()[6]->fields));

        $image = $this->image();
        $product = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_status' => 'publish', 'post_title' => 'Lamp']);
        $draft = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_status' => 'draft', 'post_title' => 'Draft']);
        $term = wp_insert_term('Lamps ' . wp_generate_password(6, false), ProductCategoryTaxonomy::NAME);
        self::assertIsArray($term);
        self::assertIsInt($product);
        self::assertIsInt($draft);
        $this->posts = [...$this->posts, $product, $draft];
        $this->terms[] = $term['term_id'];

        $values = AddOnSiteBlocks::values($tabs, [
            'accent' => '#1E40AF', 'more' => 'javascript:alert(1)', 'hero' => $image, 'pick' => $product, 'group' => $term['term_id'],
            'intro' => '<p onclick="x()">Hi</p><script>1</script>',
            'tabs' => [['title' => 'One', 'icon' => $image, 'extra' => 'no'], 'junk', ['title' => ['not text']]],
            'unknown' => 'left out',
        ]);
        self::assertSame('#1e40af', $values['accent']);
        self::assertArrayNotHasKey('more', $values, 'a javascript: address is no link');
        self::assertIsArray($values['hero']);
        self::assertSame($image, $values['hero']['id']);
        self::assertIsString($values['hero']['url'] ?? null);
        self::assertStringContainsString('/hero-', $values['hero']['url']);
        self::assertSame([$product, $term['term_id']], [$values['pick'], $values['group']]);
        self::assertSame('<p>Hi</p>1', $values['intro'], 'formatted text is cleaned as a post');
        $items = $values['tabs'];
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('One', $first['title'] ?? null);
        $icon = $first['icon'] ?? null;
        self::assertIsArray($icon);
        self::assertSame($image, $icon['id'] ?? null);
        self::assertArrayNotHasKey('extra', $first);
        self::assertSame([[], []], [$items[1] ?? null, $items[2] ?? null], 'an item is its fields, checked as settings');
        self::assertArrayNotHasKey('unknown', $values);

        $others = AddOnSiteBlocks::values($tabs, ['more' => '/cart?x=1', 'pick' => $draft, 'accent' => 'red']);
        self::assertSame(['more' => '/cart?x=1'], $others, 'a draft is no product to show, "red" no colour');
    }

    public function testABlockHoldsBlocksKnowsWhereItIsAndBringsItsFiles(): void
    {
        $tabs = $this->tabs();
        self::assertTrue($tabs->inner());

        $html = $tabs->renderIn([], new BlockPlace(5, false, '<p>inner</p><script src="/shop-block.js"></script>'));
        self::assertStringStartsWith('<div class="tabs" data-post="5"><p>inner</p><script src="/shop-block.js"></script></div>', $html, 'the inner blocks are the owner\'s, printed as they are');
        self::assertMatchesRegularExpression('#<script src="[^"]+/orillacart/extensions/blocks/tabs-[0-9a-f]{12}\.js" defer></script>#', $html);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]+/orillacart/extensions/blocks/tabs-[0-9a-f]{12}\.css">#', $html);

        $editor = $tabs->editor();
        self::assertNotNull($editor);
        self::assertSame('blocks', $editor->extension, 'the frame may call this extension\'s routes');
        self::assertStringContainsString("connect-src 'none'", html_entity_decode($editor->document, \ENT_QUOTES | \ENT_HTML5));
        self::assertMatchesRegularExpression('#<div id="editor"></div><script src="[^"]+/orillacart/extensions/blocks/tabs-editor-[0-9a-f]{12}\.js" defer></script>#', $editor->document);
    }

    public function testAPatternHoldsNothingThatRuns(): void
    {
        $hero = $this->provider('blocks_hero');
        self::assertInstanceOf(ScriptedBlockPattern::class, $hero);
        self::assertSame(['Hero', 'A big start', ['banner']], [$hero->title(), $hero->description(), $hero->categories()]);
        self::assertSame('<!-- wp:paragraph --><p >Hello</p><!-- /wp:paragraph -->', AddOnBlockPatterns::content($hero));

        $bad = $this->provider('blocks_bad');
        self::assertInstanceOf(ScriptedBlockPattern::class, $bad);
        self::assertNull(AddOnBlockPatterns::content($bad), 'a block may print an attribute as it is: HTML there is refused');
    }

    public function testTheGuidesLayoutBlockPassesTheCheck(): void
    {
        $guide = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/extension-scripts-guide.md');
        $start = strpos($guide, "site_block \"tabs\" {\n  title: \"Tabs\", inner: true");
        self::assertIsInt($start);
        $end = strpos($guide, '```', $start);
        self::assertIsInt($end);
        $script = "extension \"guide\" { name: \"Guide\", needs: { assets: [serve, scripts] } }\n" . substr($guide, $start, $end - $start);

        $checked = $this->data($this->request('POST', '/script/check', ['files' => ['main.ocs' => $script], 'id' => 'draft'], nonce: true));
        self::assertSame([], $checked['findings'] ?? null);
    }

    private function tabs(): ScriptedSiteBlock
    {
        $tabs = $this->provider('tabs');
        self::assertInstanceOf(ScriptedSiteBlock::class, $tabs);

        return $tabs;
    }

    private function provider(string $id): ScriptedProvider
    {
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($this->registrant->providers($loaded) as $provider) {
                if ($provider->id() === $id) {
                    return $provider;
                }
            }
        }
        self::fail("No provider {$id}");
    }

    /** An image of the media library. */
    private function image(): int
    {
        $uploads = wp_upload_dir();
        $file = $uploads['path'] . '/hero-' . wp_generate_password(6, false) . '.png';
        file_put_contents($file, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));
        $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Hero', 'post_status' => 'inherit'], $file);
        self::assertIsInt($id);
        $this->posts[] = $id;
        wp_update_attachment_metadata($id, ['width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path($file)]);

        return $id;
    }
}
