<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * An order an add-on places for a customer (API 22), as the shop places one by hand: priced as checkout prices
 * it, stock taken, on hold until paid unless $paid says it is; the customer emailed only when $emailCustomer says so.
 */
final readonly class NewOrderRequest
{
    /**
     * @param list<NewOrderItem>              $items
     * @param int|null                        $customerId     the WordPress user; null for a guest
     * @param string|null                     $shippingOption the way to ship, such as "rate:12"; null for the first offered, or an order that ships nothing
     * @param string|null                     $payment        the gateway's id; null for none chosen
     * @param array<string, string|bool|int>  $fields         answers to the checkout's questions, by field id
     */
    public function __construct(
        public string $email,
        public OrderAddress $billing,
        public array $items,
        public ?OrderAddress $shippingAddress = null,
        public ?int $customerId = null,
        public ?string $shippingOption = null,
        public ?string $payment = null,
        public bool $paid = false,
        public string $note = '',
        public array $fields = [],
        /** Whether the customer is emailed about it, as the shop's settings say; off unless asked. */
        public bool $emailCustomer = false,
        /** Placed to try the shop out: never emailed, not counted, removed with the test data. */
        public bool $test = false,
    ) {}
}
