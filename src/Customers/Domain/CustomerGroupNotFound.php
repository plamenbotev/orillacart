<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class CustomerGroupNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct(\sprintf('Customer group %d does not exist.', $id), 'orillacart_customer_group_not_found');
    }
}
