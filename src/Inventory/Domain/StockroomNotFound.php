<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class StockroomNotFound extends NotFound
{
    public function __construct(public readonly int $stockroomId)
    {
        parent::__construct('There is no stockroom %d.', 'orillacart_stockroom_not_found', values: [$stockroomId]);
    }
}
