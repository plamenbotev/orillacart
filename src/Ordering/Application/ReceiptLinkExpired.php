<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Shared\Domain\NotFound;

/**
 * A receipt link the shop made, too old to show the order: a new one can go to the order's email.
 */
final class ReceiptLinkExpired extends NotFound
{
    public function __construct()
    {
        parent::__construct('This link to your order has expired. We can email you a new one.', 'orillacart_receipt_expired');
    }
}
