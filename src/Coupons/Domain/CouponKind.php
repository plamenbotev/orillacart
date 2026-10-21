<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

enum CouponKind: string
{
    /** A percentage off the matching items, perhaps up to a cap. */
    case Percent = 'percent';
    /** An amount off the order, spread over the matching items. */
    case OrderAmount = 'order_amount';
    /** An amount off each matching item. */
    case ItemAmount = 'item_amount';
    /** The shipping costs nothing. */
    case FreeShipping = 'free_shipping';
    /** Buy some of the matching items, get some (the cheapest, or others the coupon names) a percentage off. */
    case BuyGet = 'buy_get';
    /** A product added to the order for nothing. */
    case FreeGift = 'free_gift';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
