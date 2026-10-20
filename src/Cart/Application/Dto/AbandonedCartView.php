<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

use OrillaCart\Cart\Domain\AbandonedCart;

/**
 * A cart left at checkout, for the report.
 */
final readonly class AbandonedCartView
{
    public function __construct(
        public int $id,
        public string $abandonedAt,
        public ?int $customerId,
        public ?string $email,
        public int $items,
        public string $subtotal,
        public ?string $remindedAt,
        public ?int $orderId,
        public ?string $orderTotal,
    ) {}

    public static function of(AbandonedCart $left): self
    {
        return new self(
            $left->id(),
            $left->abandonedAt()->format(\DATE_ATOM),
            $left->customerId(),
            $left->email(),
            $left->items(),
            $left->subtotal(),
            $left->remindedAt()?->format(\DATE_ATOM),
            $left->orderId(),
            $left->orderTotal(),
        );
    }
}
