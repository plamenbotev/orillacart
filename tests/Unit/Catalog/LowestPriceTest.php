<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Catalog;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A sale shows the lowest price of the 30 days before it began (EU Omnibus), from the prices the shop kept.
 */
final class LowestPriceTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-09-01 12:00:00 UTC');
        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', showLowestPrice: true));
        $this->shop->content->add(10, 'Lamp');
    }

    public function testASaleShowsTheLowestPriceOfThe30DaysBeforeIt(): void
    {
        $this->priced('25');
        $this->shop->clock->modify('+5 days');
        $this->priced('20');
        $this->shop->clock->modify('+35 days');
        $this->shop->products->save(10, new SaveProduct('22', sale: new SaveSale('15')));
        $this->record();

        // 25 ended more than 30 days before the sale; 20 held until the price went to 22.
        self::assertSame(['15', '22', '20'], [$this->offer()->price, $this->offer()->regularPrice, $this->offer()->lowestPrice]);
    }

    public function testNothingIsShownWithoutAHistoryOrWhenTheShopDoesNotAsk(): void
    {
        $this->shop->products->save(10, new SaveProduct('22', sale: new SaveSale('15')));
        $this->record();
        self::assertNull($this->offer()->lowestPrice, 'no price before the sale is known');

        $this->shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG'));
        $this->shop->clock->modify('+1 day');
        $this->priced('30');
        $this->shop->clock->modify('+1 day');
        $this->shop->products->save(10, new SaveProduct('30', sale: new SaveSale('25')));
        $this->record();
        self::assertNull($this->offer()->lowestPrice);
    }

    public function testAnUnchangedPriceIsKeptOnce(): void
    {
        $this->priced('25');
        $this->record();
        $this->record();

        self::assertCount(1, $this->shop->pricePoints->points);
    }

    private function priced(string $price): void
    {
        $this->shop->products->save(10, new SaveProduct($price));
        $this->record();
    }

    private function record(): void
    {
        $this->shop->priceHistory->record([$this->offer()]);
    }

    private function offer(): ProductOffer
    {
        return $this->shop->catalog->offer(10);
    }
}
