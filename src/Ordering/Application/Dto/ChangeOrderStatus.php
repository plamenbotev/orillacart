<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;
use OrillaCart\Ordering\Domain\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: move an order to another status. Cancelling puts the stock back; refunding is RefundOrder's, not a
 * status change. The note, if any, goes into the order's history.
 */
final readonly class ChangeOrderStatus
{
    public function __construct(
        #[Assert\Choice(callback: [OrderStatus::class, 'values'])]
        public string $status,
        #[Assert\Length(max: OrderNote::MAX_LENGTH)]
        public string $note = '',
    ) {}
}
