<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;
use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: move an order to another status. Refunding puts the stock back only when restock is set; cancelling
 * always does. The note, if any, goes into the order's history.
 */
final readonly class ChangeOrderStatus
{
    public function __construct(
        #[Assert\Choice(callback: [OrderStatus::class, 'values'])]
        public string $status,
        public bool $restock = false,
        #[Assert\Length(max: OrderNote::MAX_LENGTH)]
        public string $note = '',
    ) {}
}
