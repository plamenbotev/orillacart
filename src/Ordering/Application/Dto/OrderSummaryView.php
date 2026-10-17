<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\Order;

/**
 * An order as a list shows it.
 */
final readonly class OrderSummaryView
{
    public function __construct(
        public int $number,
        public string $status,
        public string $customer,
        public string $email,
        public ?int $customerId,
        public int $items,
        public string $total,
        public string $currency,
        public string $paymentName,
        public string $placedAt,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->id(),
            $order->status()->value,
            trim($order->billing()->firstName() . ' ' . $order->billing()->lastName()),
            $order->email(),
            $order->customerId(),
            array_sum(array_map(static fn($line): int => $line->quantity(), $order->lines())),
            (string) $order->total(),
            $order->currency(),
            $order->paymentName(),
            $order->placedAt()->format(\DATE_ATOM),
        );
    }
}
