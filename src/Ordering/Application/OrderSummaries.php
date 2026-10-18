<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderLine;
use OrillaCart\Extension\Order\Orders;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\Domain\Money;

/**
 * Orders as the extension API shows them to add-ons.
 */
final readonly class OrderSummaries implements Orders
{
    public function __construct(private OrderService $orders) {}

    public function find(int $number): ?OrderSummary
    {
        try {
            return self::summary($this->orders->order($number));
        } catch (OrderNotFound) {
            return null;
        }
    }

    public static function summary(OrderView $order): OrderSummary
    {
        return new OrderSummary(
            $order->number,
            $order->status,
            new \DateTimeImmutable($order->placedAt),
            $order->paidAt === null ? null : new \DateTimeImmutable($order->paidAt),
            $order->email,
            $order->customerId,
            self::address($order->billing),
            $order->shippingAddress === null ? null : self::address($order->shippingAddress),
            array_map(static fn(OrderLineView $line): OrderLine => new OrderLine($line->productId, $line->variationId, $line->sku, $line->name, $line->quantity, $line->unitPrice, $line->total, $line->tax), $order->lines),
            $order->subtotal,
            $order->discountTotal,
            $order->shippingTotal,
            (string) Money::of($order->itemsTax)->add(Money::of($order->shippingTax)),
            $order->total,
            $order->refundedTotal,
            $order->currency,
            $order->paymentGateway,
            $order->transactionId,
            $order->shipping,
            $order->note,
            array_column(array_map(static fn(OrderFieldView $field): array => [$field->id, $field->answer], $order->fields), 1, 0),
        );
    }

    private static function address(AddressData $address): OrderAddress
    {
        return new OrderAddress($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->state, $address->phone);
    }
}
