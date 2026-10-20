<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Extension\Payment\PaymentAddress;
use OrillaCart\Extension\Payment\PaymentBreakdown;
use OrillaCart\Extension\Payment\PaymentItem;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Shared\Domain\Money;

/**
 * Command: start paying for a placed order with a payment method.
 */
final readonly class StartPayment
{
    /**
     * @param list<PaymentItem> $items
     */
    public function __construct(
        public int $orderId,
        public int $orderNumber,
        public string $gateway,
        public string $amount,
        public string $currency,
        public array $items = [],
        public ?PaymentBreakdown $breakdown = null,
        public ?PaymentAddress $shipTo = null,
    ) {}

    /**
     * Paying an order's whole total, with what it holds when its parts add up to it, and where it ships when it
     * goes to the customer's door rather than a pickup point.
     */
    public static function forOrder(OrderView $order, string $gateway): self
    {
        $tax = $order->pricesIncludeTax ? Money::zero() : Money::of($order->itemsTax)->add(Money::of($order->shippingTax))->add(Money::of($order->feesTax));
        $breakdown = new PaymentBreakdown($order->subtotal, (string) $tax, $order->shippingTotal, $order->discountTotal, $order->feesTotal);
        $parts = Money::of($order->subtotal)->add($tax)->add(Money::of($order->shippingTotal))->add(Money::of($order->feesTotal))->subtract(Money::of($order->discountTotal));
        $items = array_map(static fn(OrderLineView $line): PaymentItem => new PaymentItem($line->name, $line->sku, $line->quantity, $line->unitPrice), $order->lines);
        $listed = array_reduce($items, static fn(Money $sum, PaymentItem $item): Money => $sum->add(Money::of($item->unitAmount)->times($item->quantity)), Money::zero());
        $address = $order->shippingAddress;

        return new self(
            $order->number,
            $order->number,
            $gateway,
            $order->total,
            $order->currency,
            $parts->equals(Money::of($order->total)) && $listed->equals(Money::of($order->subtotal)) ? $items : [],
            $parts->equals(Money::of($order->total)) ? $breakdown : null,
            $address === null || $order->pickupPointId !== null ? null : new PaymentAddress(trim("{$address->firstName} {$address->lastName}"), $address->street, $address->city, $address->postcode, $address->country, $address->state),
        );
    }
}
