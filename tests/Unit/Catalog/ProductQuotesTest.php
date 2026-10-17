<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tests\Fixtures\Catalog\CatalogIds;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryAttributeSetRepository;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductContent;
use OrillaCart\Tests\Fixtures\Catalog\InMemoryProductRepository;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockLevelRepository;
use OrillaCart\Tests\Fixtures\Inventory\InMemoryStockroomRepository;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxGroupRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ProductQuotesTest extends TestCase
{
    private ProductService $products;
    private StockService $stock;
    private ProductQuotes $quotes;
    private InMemoryProductContent $content;
    private int $stockroom;

    protected function setUp(): void
    {
        $ids = new CatalogIds();
        $productRepository = new InMemoryProductRepository($ids);
        $events = new RecordingDispatcher();
        $this->content = new InMemoryProductContent([10 => ['Mug', true], 20 => ['Hoodie', true], 30 => ['Draft', false]]);
        $taxGroups = new TaxGroupService(new InMemoryTaxGroupRepository(), new InMemoryTaxSettingsRepository(), new GeographyService(new InMemoryCountryRepository()), $events);
        $this->products = new ProductService($productRepository, new InMemoryAttributeSetRepository($ids), $this->content, $taxGroups, $events);
        $stockrooms = new InMemoryStockroomRepository();
        $this->stock = new StockService(new InMemoryStockLevelRepository(), $stockrooms, $events);
        $this->stockroom = new StockroomService($stockrooms)->create(new SaveStockroom('Sofia'))->id;
        $this->quotes = new ProductQuotes($productRepository, $this->content, $this->stock, new MockClock('2026-10-17 12:00:00 UTC'));
    }

    public function testAQuoteHasTheChosenOptionsTheirPriceAndTheStock(): void
    {
        $mug = $this->products->save(10, new SaveProduct('10', sku: 'MUG', weight: '0.3', attributes: [new SaveAttribute('Colour', [new SaveOption('Red'), new SaveOption('Gold', '25')])]));
        $gold = $mug->attributes[0]->options[1]->id;
        $this->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->stockroom, 7)]));
        $this->stock->setLevels(StockItemRef::option($gold), new SetStockLevels([new SetStockLevel($this->stockroom, 2)]));

        $quote = $this->quotes->quote(new QuoteRequest(10, [$gold]));

        self::assertSame([10, null, 'Mug', 'MUG', 'physical', '35', '0.3', 2, true], [$quote->productId, $quote->variationId, $quote->name, $quote->sku, $quote->type, $quote->unitPrice, $quote->weight, $quote->available, $quote->forSale]);
        self::assertSame(['Colour', 'Gold', '25'], [$quote->options[0]->attribute, $quote->options[0]->name, $quote->options[0]->adjustment]);
        self::assertSame(7, $this->quotes->quote(new QuoteRequest(10))->available);
    }

    public function testAVariationIsQuotedWithItsOwnPriceAndStock(): void
    {
        $hoodie = $this->products->save(20, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        [$small, $medium] = array_map(static fn($option): int => $option->id, $hoodie->attributes[0]->options);
        $variation = $this->products->addVariation(20, new SaveVariation([$medium], '42', sku: 'HOOD-M'));
        $this->products->addVariation(20, new SaveVariation([$small], '38', stockMode: 'unavailable'));
        $this->stock->setLevels(StockItemRef::variation($variation->id), new SetStockLevels([new SetStockLevel($this->stockroom, 0)]));

        $quote = $this->quotes->quote(new QuoteRequest(20, [$medium]));

        self::assertSame([$variation->id, 'Hoodie – M', 'HOOD-M', '42', [], 0, false], [$quote->variationId, $quote->name, $quote->sku, $quote->unitPrice, $quote->options, $quote->available, $quote->forSale]);
        self::assertSame(0, $this->quotes->quote(new QuoteRequest(20, [$small]))->available, 'marked unavailable');
    }

    public function testStockModesAndPublishingDecideWhatIsForSale(): void
    {
        $this->products->save(10, new SaveProduct('1', stockMode: 'always_available'));
        $this->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->stockroom, 0)]));
        $this->products->save(30, new SaveProduct('1'));

        self::assertNull($this->quotes->quote(new QuoteRequest(10))->available);
        self::assertTrue($this->quotes->quote(new QuoteRequest(10))->forSale);
        self::assertFalse($this->quotes->quote(new QuoteRequest(30))->forSale, 'a draft');
        $this->products->save(10, new SaveProduct('1', purchasable: false));
        self::assertFalse($this->quotes->quote(new QuoteRequest(10))->forSale, 'not purchasable');
    }

    public function testOnlyWhatTheShopSellsCanBeQuoted(): void
    {
        $this->products->save(10, new SaveProduct('1'));

        $this->expectException(InvalidSelection::class);
        $this->quotes->quote(new QuoteRequest(10, [1]));
    }

    public function testAProductNeverSavedCannotBeQuoted(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->quotes->quote(new QuoteRequest(20));
    }
}
