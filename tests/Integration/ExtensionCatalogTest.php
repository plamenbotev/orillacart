<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Exchange\Application\AddOnProductWriting;
use OrillaCart\Exchange\Application\ProductColumns;
use OrillaCart\Exchange\Application\ProductRows;
use OrillaCart\Extension\Catalog\AttributeDraft;
use OrillaCart\Extension\Catalog\ProductDraft;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;

/**
 * Design 69: an extension makes and changes products, their variations, attribute sets and categories, through the
 * extension API and its scripts; in its tests all of it is undone with the test.
 */
final class ExtensionCatalogTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "catalog" { name: "Catalog", needs: { products: [read, write, publish] } }
        object "price" { fields: { product: product, unit: money } }

        test "makes a product of its own and finds it" fn() {
          let category = shop.categories.ensure("Test kit > Mugs")
          let p = shop.products.create({ name: "Test mug", price: "12.50", sku: "TEST-MUG-1", categories: ["Test kit > Mugs"], stock: 7, attributes: [{ name: "Colour", options: ["Red", "Blue"], required: true }] })
          assert p.published == false, json.text(p)
          assert len(p.attributes) == 1 and p.attributes[0].options[1].name == "Blue", json.text(p.attributes)
          assert p.available == 7, json.text(p)
          let v = shop.products.add_variation(p.id, { options: { Colour: "Blue" }, price: "13.00", sku: "TEST-MUG-1-B", stock: 3 })
          assert v.price == money("13.00") and v.available == 3, json.text(v)
          objects.price.create({ product: p.id, unit: money("10.00") })
          let found = shop.products.search({ sku: "TEST-MUG-1" })
          assert len(found) == 1 and found[0].id == p.id, json.text(found)
          assert len(shop.products.search({ category_id: category })) == 1
          let changed = shop.products.change(p.id, { price: "15.00", attributes: [{ name: "Colour", options: ["Red", "Blue", "Green"] }] })
          assert len(changed.attributes[0].options) == 3 and changed.attributes[0].id == p.attributes[0].id, json.text(changed.attributes)
          assert changed.price == money("13.00"), "with variations, the lowest of theirs: " ~ changed.price
          assert shop.products.change_variation(p.id, v.id, { price: "14.00" }).price == money("14.00")
          shop.products.publish(p.id)
          assert shop.products.find(p.id).published
          let paths = shop.categories.list()|map(fn(c) { return c.path })
          assert "Test kit > Mugs" in paths, json.text(paths)
          let set = shop.attributes.save_set({ name: "Test sizes", attributes: [{ name: "Size", options: ["S", "M"] }] })
          let again = shop.attributes.save_set({ id: set.id, name: "Test sizes", attributes: [{ name: "Size", options: ["S", "M", "L"] }] })
          assert again.attributes[0].id == set.attributes[0].id and again.attributes[0].options[0].id == set.attributes[0].options[0].id, "kept its ids"
        }

        test "what the shop cannot take is refused" fn() {
          try { shop.products.create({ name: "No price" }) } catch e { assert e.kind == "refused", e.kind }
          let p = shop.products.create({ name: "Plain", price: "1.00", sku: "TEST-PLAIN" })
          try { shop.products.create({ name: "Twin", price: "1.00", sku: "TEST-PLAIN" }) } catch e { assert e.kind == "refused" and "TEST-PLAIN" in e.message, e.message }
          try { shop.products.add_variation(p.id, { options: { Colour: "Red" } }) } catch e { assert e.kind == "refused", e.message }
        }
        OCS;

    private ?int $stockroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        $this->request('DELETE', '/extensions/catalog', nonce: true);
        // Stock is kept in stockrooms: the test's own, should the shop have none.
        $rooms = self::kernel()->container()->get(StockroomService::class);
        self::assertInstanceOf(StockroomService::class, $rooms);
        if ($rooms->stockrooms() === []) {
            $this->stockroom = $rooms->create(new SaveStockroom('Catalog test room'))->id;
        }
    }

    protected function tearDown(): void
    {
        if ($this->stockroom !== null) {
            $rooms = self::kernel()->container()->get(StockroomService::class);
            self::assertInstanceOf(StockroomService::class, $rooms);
            $rooms->delete($this->stockroom);
        }
        parent::tearDown();
    }

    public function testAnExtensionsTestMakesProductsAttributesAndCategoriesAndLeavesNoneBehind(): void
    {
        $installed = $this->request('POST', '/extensions', ['id' => 'catalog', 'name' => 'Catalog', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true);
        self::assertSame(201, $installed->get_status(), (string) wp_json_encode($installed->get_data()));
        $enabled = $this->request('POST', '/extensions/catalog/enable', ['grant' => ['products:read', 'products:write', 'products:publish']], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));

        $answer = $this->request('POST', '/extensions/catalog/test', [], nonce: true);
        self::assertSame(200, $answer->get_status(), (string) wp_json_encode($answer->get_data()));
        $data = $answer->get_data();
        self::assertIsArray($data);
        $tests = \is_array($data['data'] ?? null) ? ($data['data']['tests'] ?? []) : ($data['tests'] ?? []);
        self::assertIsArray($tests);
        foreach ($tests as $test) {
            self::assertIsArray($test);
            self::assertTrue($test['ok'] ?? false, (string) wp_json_encode($test));
        }
        self::assertCount(2, $tests);
        $effects = \is_array($tests[0]) && \is_array($tests[0]['effects'] ?? null) ? array_column($tests[0]['effects'], 'function') : [];
        self::assertContains('shop.products.create', $effects, 'what a test made is listed');

        $lookup = self::kernel()->container()->get(ProductLookup::class);
        self::assertInstanceOf(ProductLookup::class, $lookup);
        self::assertNull($lookup->withSku('TEST-MUG-1'), 'the test\'s product went with it');
        self::assertNull($lookup->withSku('TEST-PLAIN'));
        self::assertNull(term_exists('Test kit', 'product_cat'), 'and its category');
        $this->request('DELETE', '/extensions/catalog', nonce: true);
    }

    public function testAnAddOnsTextsAreCleanedWhoeverRunsItAndANewProductIsADraft(): void
    {
        $writing = self::kernel()->container()->get(AddOnProductWriting::class);
        self::assertInstanceOf(AddOnProductWriting::class, $writing);
        $product = $writing->create(new ProductDraft(name: 'Lamp of an add-on', price: '30.00', description: '<p>Bright</p><script>alert(1)</script>', attributes: [new AttributeDraft('Shade', ['Linen', 'Paper'])]));
        try {
            self::assertFalse($product->published);
            $post = get_post($product->id);
            self::assertInstanceOf(\WP_Post::class, $post);
            self::assertSame('draft', $post->post_status);
            self::assertStringContainsString('<p>Bright</p>', $post->post_content);
            self::assertStringNotContainsString('<script', $post->post_content, 'an administrator\'s request, the add-on\'s text cleaned');
            self::assertSame(['Linen', 'Paper'], array_values($product->attributes[0]->options ?? []));

            $writing->publish($product->id);
            self::assertSame('publish', get_post_status($product->id));
        } finally {
            wp_delete_post($product->id, true);
        }
    }

    public function testAnImportedNewProductTakesTheStockItsRowGives(): void
    {
        $container = self::kernel()->container();
        $rows = $container->get(ProductRows::class);
        $rooms = $container->get(StockroomService::class);
        $stock = $container->get(StockService::class);
        self::assertInstanceOf(ProductRows::class, $rows);
        self::assertInstanceOf(StockroomService::class, $rooms);
        self::assertInstanceOf(StockService::class, $stock);
        $room = $rooms->stockrooms()[0]->id;

        // Saved as counted, it starts at 0 in the active stockroom; the row's level is written over that, not refused.
        [$added, , $id] = $rows->import(['name' => 'Row lamp', 'price' => '5.00', ProductColumns::STOCK . $room => '4']);
        try {
            self::assertTrue($added);
            self::assertSame(4, $stock->levelsOf([StockItemRef::product($id)])['product:' . $id][$room] ?? null);
        } finally {
            wp_delete_post($id, true);
        }
    }
}
