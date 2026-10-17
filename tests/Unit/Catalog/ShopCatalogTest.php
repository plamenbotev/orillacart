<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * The catalog as shoppers see it: today's price and sale, what can be bought, and the choices.
 */
final class ShopCatalogTest extends TestCase
{
    private TestShop $shop;
    private int $room;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-17 12:00:00 UTC');
        $this->room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
    }

    public function testASaleShowsWhileItRunsWithTheRegularPrice(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40', sale: new SaveSale('30', until: new \DateTimeImmutable('2026-10-20 00:00:00 UTC'))));
        $this->shop->content->add(11, 'Vase');
        $this->shop->products->save(11, new SaveProduct('25', sale: new SaveSale('20', from: new \DateTimeImmutable('2026-11-01 00:00:00 UTC'))));

        $lamp = $this->shop->catalog->offer(10);
        self::assertSame(['30', '40', null], [$lamp->price, $lamp->regularPrice, $lamp->highestPrice]);
        $vase = $this->shop->catalog->offer(11);
        self::assertSame(['25', null], [$vase->price, $vase->regularPrice], 'a sale that has not started');
    }

    public function testStockAndPublishingDecideWhatCanBeBought(): void
    {
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('40'));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 0)]));
        $this->shop->content->add(11, 'Draft', false);
        $this->shop->products->save(11, new SaveProduct('5', stockMode: 'always_available'));
        $this->shop->content->add(12, 'Poster');
        $this->shop->products->save(12, new SaveProduct('5', stockMode: 'always_available'));

        self::assertSame([false, 0], [$this->shop->catalog->offer(10)->forSale, $this->shop->catalog->offer(10)->available]);
        self::assertFalse($this->shop->catalog->offer(11)->forSale, 'not published');
        self::assertSame([true, null], [$this->shop->catalog->offer(12)->forSale, $this->shop->catalog->offer(12)->available]);
    }

    public function testAProductWithVariationsOffersOnlyTheirOptionsFromItsLowestPrice(): void
    {
        $this->shop->content->add(10, 'Hoodie');
        $saved = $this->shop->products->save(10, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M'), new SaveOption('L')])]));
        [$small, $medium] = [$saved->attributes[0]->options[0]->id, $saved->attributes[0]->options[1]->id];
        $this->shop->products->addVariation(10, new SaveVariation([$small], '38', stockMode: 'always_available'));
        $sold = $this->shop->products->addVariation(10, new SaveVariation([$medium], '42', new SaveSale('35'), stockMode: 'unavailable'));

        $offer = $this->shop->catalog->offer(10);

        self::assertSame(['35', '38', null], [$offer->price, $offer->highestPrice, $offer->regularPrice], 'the lowest price today; the highest when they differ');
        self::assertSame(['S', 'M'], array_map(static fn($option): string => $option->name, $offer->attributes[0]->options), 'L has no variation');
        self::assertSame([[true, '38', null], [false, '35', '42']], array_map(static fn($variation): array => [$variation->forSale, $variation->price, $variation->regularPrice], $offer->variations));
        self::assertSame($sold->id, $offer->variations[1]->id);
        self::assertTrue($offer->forSale, 'one variation can be bought');
        self::assertFalse($offer->isSimple());
    }

    public function testOffersKeepTheAskedOrderAndLeaveOutProductsWithoutDetails(): void
    {
        foreach ([10 => 'A', 11 => 'B'] as $id => $name) {
            $this->shop->content->add($id, $name);
            $this->shop->products->save($id, new SaveProduct('1'));
        }
        $this->shop->content->add(12, 'Never saved');

        self::assertSame([11, 10], array_keys($this->shop->catalog->offers([11, 12, 10])));
        $this->expectException(ProductNotFound::class);
        $this->shop->catalog->offer(12);
    }
}
