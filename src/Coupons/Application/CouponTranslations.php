<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Application;

use OrillaCart\Translations\Application\TranslatableType;

/** A coupon's description, which shoppers read on the cart, the receipt and the promotion blocks, in the site's other languages. */
final readonly class CouponTranslations implements TranslatableType
{
    public const string TYPE = 'coupon';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return ['description' => 255];
    }
}
