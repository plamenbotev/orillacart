<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Cart;

use OrillaCart\Cart\Application\CartLinks;

final class FakeCartLinks implements CartLinks
{
    public function reminderUrl(?string $recoverToken, ?string $coupon): string
    {
        $query = http_build_query(array_filter(['cart' => $recoverToken, 'coupon' => $coupon], static fn(?string $value): bool => $value !== null));

        return $recoverToken === null ? 'https://shop.test/login?to=cart&' . $query : 'https://shop.test/cart?' . $query;
    }
}
