<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\AttributeSetService;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveAttributeSet;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shipping\Application\ShippingClassService;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tests\Fixtures\Catalog\CatalogIds;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryAttributeSetRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductContent;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductRepository;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryCarrierRepository;
use OrillaCart\Tests\Fixtures\Shipping\InMemoryShippingClassRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    private RecordingDispatcher $events;
    private InMemoryProductContent $content;
    private TaxGroupService $taxGroups;
    private ProductService $products;
    private AttributeSetService $sets;

    protected function setUp(): void
    {
        $ids = new CatalogIds();
        $productRepository = new InMemoryProductRepository($ids);
        $setRepository = new InMemoryAttributeSetRepository($ids);
        $this->events = new RecordingDispatcher();
        $this->content = new InMemoryProductContent([10 => ['Hoodie', true], 11 => ['Mug', true]]);
        $this->taxGroups = new TaxGroupService(new InMemoryTaxGroupRepository(), new InMemoryTaxSettingsRepository(), new GeographyService(new InMemoryCountryRepository()), $this->events);
        $this->products = new ProductService($productRepository, $setRepository, $this->content, $this->taxGroups, new ShippingClassService(new InMemoryShippingClassRepository(), new InMemoryCarrierRepository(), $this->events), $this->events);
        $this->sets = new AttributeSetService($setRepository, $productRepository, $this->events);
    }

    public function testChangingOrDeletingASetChangesTheProductsUsingIt(): void
    {
        $set = $this->sets->create(new SaveAttributeSet('Clothing', attributes: [new SaveAttribute('Size', [new SaveOption('S')])]));
        $this->products->save(10, new SaveProduct('40', setIds: [$set->id]));
        $this->products->save(11, new SaveProduct('8'));
        $changed = fn(): array => array_values(array_map(static fn(ProductChanged $event): int => $event->productId, array_filter($this->events->events, static fn(object $event): bool => $event instanceof ProductChanged)));

        $this->events->events = [];
        $this->sets->update($set->id, new SaveAttributeSet('Clothes', attributes: [new SaveAttribute('Size', [new SaveOption('S', id: $set->attributes[0]->options[0]->id)], id: $set->attributes[0]->id)]));
        self::assertSame([10], $changed());

        $this->events->events = [];
        $this->sets->delete($set->id);
        self::assertSame([10], $changed());
    }

    public function testAProductPostStartsWithDefaultsUntilSaved(): void
    {
        $view = $this->products->product(10);

        self::assertFalse($view->saved);
        self::assertSame(['Hoodie', 'physical', '0', 'tracked'], [$view->name, $view->type, $view->price, $view->stockMode]);
    }

    public function testThereIsNoProductWithoutItsPost(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->products->save(99, new SaveProduct('1'));
    }

    public function testSavingCreatesThenChangesTheProduct(): void
    {
        $group = $this->taxGroups->create(new SaveTaxGroup('Reduced'));
        $sale = new SaveSale('15', new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));

        $view = $this->products->save(10, new SaveProduct('19.90', sku: 'HOOD-1', taxGroupId: $group->id, sale: $sale, weight: '0.450', attributes: [
            new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('XL', '2.5')], required: true),
        ]));
        $again = $this->products->save(10, new SaveProduct('21', type: 'virtual', stockMode: 'always_available', attributes: [
            new SaveAttribute('Size', [new SaveOption('XL', '3', $view->attributes[0]->options[1]->id)], id: $view->attributes[0]->id),
        ]));

        self::assertTrue($view->saved);
        self::assertSame(['19.9', 'HOOD-1', $group->id, '15', '0.45'], [$view->price, $view->sku, $view->taxGroupId, $view->sale?->price, $view->weight]);
        self::assertSame(['21', 'virtual', 'always_available', null, null], [$again->price, $again->type, $again->stockMode, $again->sku, $again->sale]);
        self::assertSame(['XL'], array_map(static fn($option): string => $option->name, $again->attributes[0]->options));
        self::assertSame($view->attributes[0]->options[1]->id, $again->attributes[0]->options[0]->id);
        self::assertEquals([new ProductChanged(10), new ProductChanged(10), new CatalogItemsRemoved(optionIds: [$view->attributes[0]->options[0]->id])], $this->events->events);
    }

    public function testASkuBelongsToOneProductOrVariation(): void
    {
        $this->products->save(10, new SaveProduct('1', sku: 'SKU-1'));
        $this->products->save(10, new SaveProduct('2', sku: 'SKU-1'));

        $this->expectException(CatalogRuleViolation::class);
        $this->expectExceptionMessage('Another product or variation has the SKU "SKU-1".');
        $this->products->save(11, new SaveProduct('1', sku: 'SKU-1'));
    }

    public function testReferencesMustExist(): void
    {
        foreach (['tax group' => new SaveProduct('1', taxGroupId: 5), 'attribute set' => new SaveProduct('1', setIds: [5])] as $what => $command) {
            try {
                $this->products->save(10, $command);
                self::fail("An unknown {$what} was accepted.");
            } catch (CatalogRuleViolation $e) {
                self::assertStringContainsString("There is no {$what} 5.", $e->getMessage());
            }
        }
    }

    public function testVariationsAndSetsWorkTogether(): void
    {
        $set = $this->sets->create(new SaveAttributeSet('Clothing', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        [$small, $medium] = array_map(static fn($option): int => $option->id, $set->attributes[0]->options);
        $this->products->save(10, new SaveProduct('40', setIds: [$set->id]));

        $variation = $this->products->addVariation(10, new SaveVariation([$small], '38', sku: 'HOOD-S'));
        $changed = $this->products->changeVariation(10, $variation->id, new SaveVariation([$medium], '41', sku: 'HOOD-S'));

        self::assertSame(['M', '41', 'HOOD-S'], [$changed->label, $changed->price, $changed->sku]);
        self::assertCount(1, $this->products->product(10)->variations);

        try {
            $this->sets->delete($set->id);
            self::fail('A set the variations use was deleted.');
        } catch (CatalogRuleViolation $e) {
            self::assertSame('orillacart_attribute_set_in_use', $e->errorCode);
        }
        try {
            $this->sets->update($set->id, new SaveAttributeSet('Clothing', attributes: [new SaveAttribute('Size', [new SaveOption('S', id: $small)], id: $set->attributes[0]->id)]));
            self::fail('An option a variation uses was removed.');
        } catch (CatalogRuleViolation $e) {
            self::assertSame('orillacart_option_in_use', $e->errorCode);
        }

        $this->events->events = [];
        $this->products->removeVariation(10, $variation->id);
        self::assertEquals([new ProductChanged(10), new CatalogItemsRemoved(variationIds: [$variation->id])], $this->events->events);
        $this->sets->delete($set->id);
    }

    public function testDeletingAProductForgetsItsVariationsAndOptions(): void
    {
        $view = $this->products->save(10, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S')])]));
        $optionId = $view->attributes[0]->options[0]->id;
        $variation = $this->products->addVariation(10, new SaveVariation([$optionId], '38'));
        $this->events->events = [];

        $this->products->delete(10);
        $this->products->delete(10);

        self::assertEquals([new CatalogItemsRemoved([10], [$variation->id], [$optionId])], $this->events->events);
        self::assertFalse($this->products->product(10)->saved);
        self::assertSame([], $this->content->variations, 'the variation posts went with the product');
    }

    public function testAVariationHasAPostOfItsOwnUnderTheProduct(): void
    {
        $view = $this->products->save(10, new SaveProduct('40', weight: '1.2', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('XL')])]));
        [$small, $large] = array_map(static fn($option): int => $option->id, $view->attributes[0]->options);

        $variation = $this->products->addVariation(10, new SaveVariation([$large], '48', weight: '1.6'));

        self::assertSame([$variation->id => 10], $this->content->variations, 'its post is a child of the product\'s, and lends it its id');
        self::assertSame(['1.6', null], [$variation->weight, $variation->length]);

        // A refused variation leaves no post behind.
        try {
            $this->products->addVariation(10, new SaveVariation([$large], '50'));
            self::fail('Two variations had the same options.');
        } catch (CatalogRuleViolation $e) {
            self::assertSame('orillacart_duplicate_variation', $e->errorCode);
        }
        self::assertCount(1, $this->content->variations);

        // Deleting the post in WordPress forgets the variation; removing the variation deletes its post.
        $other = $this->products->addVariation(10, new SaveVariation([$small], '38'));
        $this->products->forgetVariation($other->id);
        $this->products->forgetVariation($other->id);
        self::assertSame([$variation->id], array_map(static fn($kept): int => $kept->id, $this->products->product(10)->variations));
        $this->products->removeVariation(10, $variation->id);
        self::assertSame([], $this->content->variations);
    }

    public function testQuickEditChangesThePriceAndKeepsTheSale(): void
    {
        $this->products->save(10, new SaveProduct('40', sale: new SaveSale('30'), sku: 'HOOD'));

        $this->products->changePrice(10, '45');

        $product = $this->products->product(10);
        self::assertSame(['45', '30', 'HOOD'], [$product->price, $product->sale?->price, $product->sku]);

        try {
            $this->products->changePrice(10, '29');
            self::fail('the sale of 30 would be charged above a price of 29');
        } catch (CatalogRuleViolation $e) {
            self::assertSame('orillacart_sale_not_below_price', $e->errorCode);
        }
        self::assertSame('45', $this->products->product(10)->price);
        self::assertSame('45', $this->products->ownPrice(10));
    }

    public function testQuickEditLeavesAProductWithVariationsAlone(): void
    {
        $set = $this->sets->create(new SaveAttributeSet('Clothing', attributes: [new SaveAttribute('Size', [new SaveOption('S')])]));
        $this->products->save(10, new SaveProduct('40', setIds: [$set->id]));
        $this->products->addVariation(10, new SaveVariation([$set->attributes[0]->options[0]->id], '25'));

        self::assertNull($this->products->ownPrice(10), 'the list shows 25, the cheapest variation');
        try {
            $this->products->changePrice(10, '25');
            self::fail('the product\'s own price is not what it sells for');
        } catch (CatalogRuleViolation $e) {
            self::assertSame('orillacart_price_not_its_own', $e->errorCode);
        }
        self::assertSame('40', $this->products->product(10)->price);
    }
}
