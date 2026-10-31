<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Coupons;

use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Coupons\Application\Dto\SaveCoupon;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A coupon or price rule naming a product deleted since says so, for the shop to choose another: it no longer
 * applies to the product, and a free gift that is gone gives nothing.
 */
final class DeletedProductsTest extends TestCase
{
    public function testACouponAndAPriceRuleTellOfTheProductsThatAreGone(): void
    {
        $shop = new TestShop();
        $shop->content->add(10, 'Lamp');

        $coupon = $shop->coupons->create(new SaveCoupon('', 'free_gift', '0', 'A shade with a lamp', productIds: [10], automatic: true, getQuantity: 1, giftProductId: 30));
        self::assertSame([30], $coupon->missingProductIds, 'the gift was deleted');

        $rule = $shop->priceRules->create(new SavePriceRule('Lamps', [new PriceTierData(3, 'percent', '10')], productIds: [10, 40]));
        self::assertSame([40], $rule->missingProductIds);
    }
}
