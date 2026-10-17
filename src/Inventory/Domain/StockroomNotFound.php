<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class StockroomNotFound extends NotFound
{
    public function __construct(public readonly int $stockroomId)
    {
        parent::__construct(\sprintf('There is no stockroom %d.', $stockroomId), 'orillacart_stockroom_not_found');
    }
}
