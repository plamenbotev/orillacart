<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * An order as add-ons may read it. Amounts are decimal numbers in the order's currency, before tax unless named
 * otherwise; the total is what the customer pays.
 */
final readonly class OrderSummary
{
    /**
     * @param string                $status     pending_payment, on_hold, processing, shipped, completed, cancelled, failed or refunded
     * @param int|null              $customerId the WordPress user, null for a guest's order
     * @param list<OrderLine>       $lines
     * @param string|null           $payment    the payment gateway's id, null for an order with nothing to pay
     * @param string|null           $shipping   how it ships, as the customer chose it: "Courier – Express"
     * @param array<string, string> $fields     the answers to checkout's questions (add-ons' and the shop's forms), by field id
     * @param array<string, string> $fees       what it pays besides its items and shipping, amount by label, as its
     *                                          prices are (since version 5)
     */
    public function __construct(
        public int $number,
        public string $status,
        public \DateTimeImmutable $placedAt,
        public ?\DateTimeImmutable $paidAt,
        public string $email,
        public ?int $customerId,
        public OrderAddress $billing,
        public ?OrderAddress $shippingAddress,
        public array $lines,
        public string $subtotal,
        public string $discount,
        public string $shippingTotal,
        public string $tax,
        public string $total,
        public string $refunded,
        public string $currency,
        public ?string $payment,
        public ?string $transactionId,
        public ?string $shipping,
        public string $note,
        public array $fields,
        public array $fees = [],
    ) {}
}
