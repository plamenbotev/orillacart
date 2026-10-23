<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * The shop changes the items of an order that waits for an offline payment, as when the customer calls:
 * the order's stock is put back, the new items are priced as checkout prices them (with the shop's own unit
 * prices where it set them) and their stock is taken, all at once or not at all. The order keeps its coupons,
 * priced again; one that no longer holds is dropped, and the history says why.
 */
final readonly class OrderEditing
{
    public function __construct(
        private OrderService $orders,
        private OrderPricing $pricing,
        private Transactions $transactions,
        private ShippingQuotes $shipping,
        private Discounts $discounts,
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
        $billing = new TaxAddress($order->billing->country, $order->billing->state, $order->billing->postcode);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $items = array_map(static fn(OrderLineView $line): array => ['product_id' => $line->productId, 'option_ids' => $line->optionIds, 'bundle_choices' => $line->bundleChoices, 'quantity' => $line->quantity, 'unit_price' => $line->unitPrice, 'personalisation' => $line->personalisation], $order->lines);

        $codes = array_map(static fn(OrderDiscountView $discount): string => $discount->code, $order->discounts);

        return $this->pricing->price($items, new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode), $billing, $order->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge)['options'];
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
        $billing = new TaxAddress($order->billing->country, $order->billing->state, $order->billing->postcode);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $shipping = new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode);
        // A line the shop keeps keeps its answers, and what they cost when the catalog's price applies again.
        $items = array_map(static function (EditedItem $item) use ($order): array {
            $answers = $item->line !== null && ($order->lines[$item->line] ?? null)?->productId === $item->productId ? $order->lines[$item->line]->personalisation : [];

            return ['product_id' => $item->productId, 'option_ids' => $item->optionIds, 'bundle_choices' => $item->bundleChoices, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice, 'personalisation' => $answers, 'extra' => (string) array_reduce($answers, static fn(Money $sum, array $answer): Money => $answer['price'] === null ? $sum : $sum->add(Money::of($answer['price'])), Money::zero())];
        }, $command->items);

        $keptPoint = $order->pickupPointId;
        $codes = array_map(static fn(OrderDiscountView $discount): string => $discount->code, $order->discounts);

        return $this->transactions->run(function () use ($order, $orderId, $items, $shipping, $billing, $command, $shippingAddress, $userId, $keptPoint, $codes): OrderView {
            $this->orders->releaseStock($orderId);
            // Its own uses do not count against the limits while it is priced again.
            $this->discounts->release($orderId);
            $priced = $this->pricing->price($items, $shipping, $billing, $command->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge, $order->paymentGateway);
            if ($priced['needs_shipping'] && $priced['chosen']?->id !== $command->shippingOption) {
                throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to its address.' : 'Choose how the order ships.', 'orillacart_shipping_required');
            }
            $bill = $priced['bill'];
            // The order keeps its office or locker unless the shop chose another.
            $pickupPoint = $this->pricing->pickupPoint($priced, $shippingAddress->country, $shippingAddress->postcode, $command->pickupPoint ?? $keptPoint);

            $this->discounts->redeem($orderId, $priced['discounts']->coupons, $order->customerId, $order->email);
            foreach ($priced['discounts']->refused as $code => $reason) {
                $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The code %s no longer applies, so the order lost it: %s', $code, $reason)), $userId);
            }

            return $this->orders->replaceItems($orderId, new NewOrderItems(
                $this->pricing->orderLines($priced),
                $this->pricing->shipping($priced, $pickupPoint),
                $priced['needs_shipping'] ? $shippingAddress : null,
                (string) $bill->total(),
                $bill->includesTax(),
            ), $userId);
        });
    }
}
