<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

/**
 * Where customers see an order.
 */
interface OrderLinks
{
    /** The page showing the order with this key: where checkout and payment providers send the customer. */
    public function receiptUrl(string $key): string;

    /** The link that downloads a bought file. */
    public function downloadUrl(string $token): string;
}
