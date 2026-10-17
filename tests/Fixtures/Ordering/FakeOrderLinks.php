<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Application\OrderLinks;

final class FakeOrderLinks implements OrderLinks
{
    public function receiptUrl(string $key): string
    {
        return "https://shop.test/?orillacart_order={$key}";
    }
}
