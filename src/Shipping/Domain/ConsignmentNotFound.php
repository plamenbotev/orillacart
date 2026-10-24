<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class ConsignmentNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no shipment %d.', 'orillacart_shipment_not_found', values: [$id]);
    }
}
