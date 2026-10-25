<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

/**
 * What checkout's last button says. In the EU it must say plainly that ordering means paying (Consumer Rights
 * Directive art. 8(2)): "Buy now" or "Order with obligation to pay", not "Place order".
 */
enum OrderButton: string
{
    case PlaceOrder = 'place_order';
    case BuyNow = 'buy_now';
    case OrderAndPay = 'order_and_pay';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
