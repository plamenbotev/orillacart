<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Setup;

use OrillaCart\Setup\Domain\Regions;
use OrillaCart\Setup\Domain\ShippingZone;
use PHPUnit\Framework\TestCase;

final class ShippingZoneTest extends TestCase
{
    private const array EU = ['AT', 'DE', 'FR'];

    public function testAShopInTheEuSellingEverywhereHasItsCountryTheRestOfTheEuAndTheWorld(): void
    {
        $zones = ShippingZone::of('DE', Regions::World, self::EU);

        self::assertSame(['home', 'eu', 'world'], array_map(static fn(ShippingZone $zone): string => $zone->id, $zones));
        self::assertSame(['AT', 'FR'], $zones[1]->countries, 'the EU without home');
        self::assertSame([], $zones[2]->countries, 'everywhere');
        self::assertSame(['DE', 'AT', 'FR'], $zones[2]->excluded, 'but the zones before it');
    }

    public function testEuropeIsTheEuAndTheRestOfEurope(): void
    {
        $zones = ShippingZone::of('DE', Regions::Europe, self::EU);

        self::assertSame(['home', 'eu', 'europe'], array_map(static fn(ShippingZone $zone): string => $zone->id, $zones));
        self::assertContains('GB', $zones[2]->countries);
        self::assertNotContains('DE', $zones[2]->countries);
        self::assertNotContains('RU', $zones[2]->countries, 'sanctions');
    }

    public function testAShopOutsideTheEuSellingEverywhereHasItsCountryAndTheWorld(): void
    {
        self::assertSame(['home', 'world'], array_map(static fn(ShippingZone $zone): string => $zone->id, ShippingZone::of('US', Regions::World, self::EU)));
        self::assertSame(['home'], array_map(static fn(ShippingZone $zone): string => $zone->id, ShippingZone::of('US', Regions::Home, self::EU)));
        $listed = ShippingZone::of('US', Regions::Listed, self::EU, ['US', 'CA', 'MX']);
        self::assertSame(['CA', 'MX'], $listed[1]->countries, 'the others listed');
    }
}
