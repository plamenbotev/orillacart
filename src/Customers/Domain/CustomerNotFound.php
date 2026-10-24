<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CustomerNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no customer %d.', 'orillacart_customer_not_found', values: [$id]);
    }
}
