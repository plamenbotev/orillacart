<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\Units;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveRange;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testChangingTheShopsUnitsConvertsWhatIsMeasuredSoRatesTakeTheSameParcels(): void
    {
        $shop = new TestShop();
        $shop->content->add(10, 'Lamp');
        $shop->products->save(10, new SaveProduct('30', weight: '2.5', length: '40', width: '20', height: '10'));
        $carrier = $shop->carriers->create(new SaveCarrier('Post', rates: [
            new SaveShippingRate('Light', '5', regions: ['BG'], weight: new SaveRange('0', '3')),
            new SaveShippingRate('Short', '6', regions: ['BG'], length: new SaveRange('0', '45')),
        ]));
        $owner = new CartOwner($shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);
        $offered = static fn(): array => array_map(static fn($option): string => $option->name, $shop->checkout->summary($owner, new CheckoutDetails('BG'))->shippingOptions);
        self::assertSame(['Light', 'Short'], $offered());

        $shop->settingsService->update(new UpdateSettings('EUR', null, 'g', 'mm', 'BG', null));

        $product = $shop->products->product(10);
        self::assertSame(['2500', '400', '200', '100'], array_map(static fn(?string $measure): string => Units::decimal((float) $measure), [$product->weight, $product->length, $product->width, $product->height]));
        self::assertSame(['Light', 'Short'], $offered(), 'the same parcel, in grams and millimetres');
        $rates = $shop->carriers->carrier($carrier->id)->rates;
        self::assertSame(['3000', '450'], [$rates[0]->weight?->max, $rates[1]->length?->max]);
    }

    public function testAddOnsGetTheShipmentInTheUnitsTheyWant(): void
    {
        $shipment = new Shipment('BG', null, null, [new ShipmentItem(2, '500', '400', '300', '200')], '10', 'EUR', 'g', 'mm');

        self::assertSame(['1000', '1', '2.2046'], [$shipment->weight(), $shipment->weight('kg'), $shipment->weight('lb')]);
        self::assertSame('40', $shipment->length('400', 'cm'));
        // 2 × 40 × 30 × 20 cm³ = 48000 cm³, at 5000 cm³ to the kilogram.
        self::assertSame(['9.6', '9600'], [$shipment->dimensionalWeight('kg'), $shipment->dimensionalWeight()]);
    }
}
