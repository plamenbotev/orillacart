<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * The shop changes the items of an order that waits for an offline payment, as when the customer calls:
 * the order's stock is put back, the new items are priced as checkout prices them (with the shop's own unit
 * prices where it set them) and their stock is taken, all at once or not at all.
 */
final readonly class OrderEditing
{
    public function __construct(
        private OrderService $orders,
        private OrderPricing $pricing,
        private Transactions $transactions,
        private ShippingQuotes $shipping,
    ) {}

    /**
     * The ways an order on hold can ship to its address, with its items as they are: the choices the shop has
     * when it changes how the order ships.
     *
     * @return list<ShippingChoiceView>
     *
     * @throws OrderNotFound
     */
    public function shippingOptions(int $orderId): array
    {
        $order = $this->orders->order($orderId);
        $billing = new TaxAddress($order->billing->country, $order->billing->state);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $items = array_map(static fn(OrderLineView $line): array => ['product_id' => $line->productId, 'option_ids' => $line->optionIds, 'quantity' => $line->quantity, 'unit_price' => $line->unitPrice], $order->lines);

        return $this->pricing->price($items, new TaxAddress($shippingAddress->country, $shippingAddress->state), $billing, $order->shippingOption, $shippingAddress->postcode)['options'];
    }

    /**
     * The offices or lockers a service delivers the order to near its address, for changing how it ships.
     *
     * @return list<PickupPointView>
     *
     * @throws OrderNotFound
     */
    public function pickupPoints(int $orderId, string $shippingOption): array
    {
        $order = $this->orders->order($orderId);
        $address = $order->shippingAddress ?? $order->billing;

        return $this->shipping->pickupPoints($shippingOption, $address->country, $address->postcode, $address->city);
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation    for an order not on hold
     * @throws CheckoutRuleViolation for items not sold or not in stock, or a shipping rate that is not offered
     */
    public function edit(int $orderId, EditOrderItems $command, ?int $userId): OrderView
    {
        $order = $this->orders->order($orderId);
        if ($order->status !== 'on_hold') {
            throw new OrderRuleViolation(\sprintf('Only orders on hold can change their items; order #%d is %s.', $orderId, str_replace('_', ' ', $order->status)), 'orillacart_order_not_editable');
        }
        $billing = new TaxAddress($order->billing->country, $order->billing->state);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $shipping = new TaxAddress($shippingAddress->country, $shippingAddress->state);
        $items = array_map(static fn(EditedItem $item): array => ['product_id' => $item->productId, 'option_ids' => $item->optionIds, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice], $command->items);

        $keptPoint = $order->pickupPointId;

        return $this->transactions->run(function () use ($orderId, $items, $shipping, $billing, $command, $shippingAddress, $userId, $keptPoint): OrderView {
            $this->orders->releaseStock($orderId);
            $priced = $this->pricing->price($items, $shipping, $billing, $command->shippingOption, $shippingAddress->postcode);
            if ($priced['needs_shipping'] && $priced['chosen']?->id !== $command->shippingOption) {
                throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to its address.' : 'Choose how the order ships.', 'orillacart_shipping_required');
            }
            $bill = $priced['bill'];
            // The order keeps its office or locker unless the shop chose another.
            $pickupPoint = $this->pricing->pickupPoint($priced, $shippingAddress->country, $shippingAddress->postcode, $command->pickupPoint ?? $keptPoint);

            return $this->orders->replaceItems($orderId, new NewOrderItems(
                $this->pricing->lines($priced),
                $this->pricing->shipping($priced, $pickupPoint),
                $priced['needs_shipping'] ? $shippingAddress : null,
                $this->pricing->taxLines($priced),
                (string) $bill->subtotal(),
                (string) $bill->itemsTax(),
                (string) $bill->total(),
            ), $userId);
        });
    }
}
