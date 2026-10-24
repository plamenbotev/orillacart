<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * A return of an order's items, as the order screen and the customer's receipt show it.
 */
final readonly class ReturnView
{
    /**
     * @param string                 $status requested, approved, refused, received or refunded
     * @param list<ReturnedItemView> $items
     * @param string                 $amount what refunding the items gives back, with their tax
     */
    public function __construct(
        public int $id,
        public string $status,
        public array $items,
        public string $reason,
        public Text $reasonLabel,
        public string $comment,
        public string $answer,
        public string $amount,
        public string $requestedAt,
        public ?string $answeredAt,
        public ?string $receivedAt,
        public ?string $refundedAt,
    ) {}
}
