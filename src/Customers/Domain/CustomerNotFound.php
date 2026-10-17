<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CustomerNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('There is no customer %d.', $id), 'orillacart_customer_not_found');
    }
}
