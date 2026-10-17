<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class OrderNotFound extends NotFound
{
    public function __construct()
    {
        parent::__construct('There is no such order.', 'orillacart_order_not_found');
    }
}
