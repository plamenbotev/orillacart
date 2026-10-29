<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AdjustOrderPrice;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\NewOrderItems;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\PriceChangeView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Lifecycle\Languages;
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
        private Languages $languages,
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
        $billing = new TaxAddress($order->billing->country, $order->billing->state, $order->billing->postcode, $order->billing->city, $order->billing->street);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $items = array_map(static fn(OrderLineView $line): array => ['product_id' => $line->productId, 'option_ids' => $line->optionIds, 'bundle_choices' => $line->bundleChoices, 'quantity' => $line->quantity, 'unit_price' => $line->unitPrice, 'personalisation' => $line->personalisation], $order->lines);

        [$codes, $automatic] = self::discountsOf($order);

        return $this->pricing->price($items, new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode, $shippingAddress->city, $shippingAddress->street), $billing, $order->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge, $order->paymentGateway, $order->currency, automatic: $automatic)['options'];
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
        $order = $this->orderOnHold($orderId);
        $kept = [];
        foreach ($command->items as $index => $item) {
            if ($item->line !== null && ($order->lines[$item->line] ?? null)?->productId === $item->productId) {
                $kept[$item->line] = $index;
            }
        }

        return $this->reprice($order, $command, self::changesOf($order, $kept), $userId);
    }

    /**
     * Changes the price of an order on hold, or of one of its items, and prices it again: its taxes follow. The
     * order keeps who changed it and why.
     *
     * @param string $kind   PriceAdjustment::MANUAL for the shop's, ADD_ON for an add-on's
     * @param string $source the shop manager's user id, or the add-on's id
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation    for an order not on hold
     * @throws CheckoutRuleViolation for an item it does not have, or a change taking off more than an item costs
     */
    public function adjust(int $orderId, AdjustOrderPrice $command, ?int $userId, string $kind = PriceAdjustment::MANUAL, ?string $source = null): OrderView
    {
        $order = $this->orderOnHold($orderId);
        if ($command->line !== null && !isset($order->lines[$command->line])) {
            throw new CheckoutRuleViolation('Order #%1$d has no item %2$d.', 'orillacart_invalid_price_change', values: [$orderId, $command->line + 1]);
        }
        $changes = self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines)));
        $changes[] = [
            'kind' => $kind,
            'source' => $source ?? ($userId === null ? 'shop' : (string) $userId),
            'label' => trim($command->label),
            'amount' => $command->amount,
            'line' => $command->line,
            'reason' => trim($command->reason) === '' ? null : trim($command->reason),
            'change' => null,
        ];
        $view = $this->reprice($order, self::asItIs($order), $changes, $userId);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf(
            '%s: %s %s%s%s.',
            trim($command->label),
            $command->amount,
            $order->currency,
            $command->line === null ? ' on the whole order' : \sprintf(' on %s', $order->lines[$command->line]->name),
            trim($command->reason) === '' ? '' : ' (' . trim($command->reason) . ')',
        )), $userId);

        return $this->orders->order($orderId);
    }

    /**
     * Takes a change to the price off an order on hold, and prices it again; one for the whole order goes from
     * every item.
     *
     * @param string $change the change's key
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation    for an order not on hold
     * @throws CheckoutRuleViolation for a change the order does not have, or one that is not the shop's or an add-on's
     */
    public function removeAdjustment(int $orderId, string $change, ?int $userId): OrderView
    {
        $order = $this->orderOnHold($orderId);
        $removed = array_values(array_filter($order->priceChanges, static fn(PriceChangeView $made): bool => $made->change === $change && $made->removable))[0] ?? null;
        if ($removed === null) {
            throw new CheckoutRuleViolation('The order has no such change to its price; coupons go with their codes.', 'orillacart_unknown_price_change');
        }
        $changes = array_values(array_filter(
            self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines))),
            static fn(array $kept): bool => $kept['change'] !== $change,
        ));
        $this->reprice($order, self::asItIs($order), $changes, $userId);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The change "%s" to the price was taken off.', $removed->label)), $userId);

        return $this->orders->order($orderId);
    }

    /**
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     */
    private function reprice(OrderView $order, EditOrderItems $command, array $changes, ?int $userId): OrderView
    {
        $orderId = $order->number;
        $billing = new TaxAddress($order->billing->country, $order->billing->state, $order->billing->postcode, $order->billing->city, $order->billing->street);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $shipping = new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode, $shippingAddress->city, $shippingAddress->street);
        // A line the shop keeps keeps its answers, and what they cost when the catalog's price applies again.
        $items = array_map(static function (EditedItem $item) use ($order): array {
            $answers = $item->line !== null && ($order->lines[$item->line] ?? null)?->productId === $item->productId ? $order->lines[$item->line]->personalisation : [];

            return ['product_id' => $item->productId, 'option_ids' => $item->optionIds, 'bundle_choices' => $item->bundleChoices, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice, 'personalisation' => $answers, 'extra' => (string) array_reduce($answers, static fn(Money $sum, array $answer): Money => $answer['price'] === null ? $sum : $sum->add(Money::of($answer['price'])), Money::zero())];
        }, $command->items);

        $keptPoint = $order->pickupPointId;
        [$codes, $automatic] = self::discountsOf($order);

        // Priced in the customer's language, so the order's lines read as theirs did (design 51).
        return $this->languages->in($order->locale, fn(): OrderView => $this->transactions->run(function () use ($order, $orderId, $items, $shipping, $billing, $command, $shippingAddress, $userId, $keptPoint, $codes, $automatic, $changes): OrderView {
            $this->orders->releaseStock($orderId);
            // Its own uses do not count against the limits while it is priced again.
            $this->discounts->release($orderId);
            // In the order's currency, with its own automatic discounts: not ones the shop started since.
            $priced = $this->pricing->price($items, $shipping, $billing, $command->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge, $order->paymentGateway, $order->currency, $changes, $automatic);
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
            $kept = array_map(static fn(AppliedCoupon $coupon): string => $coupon->code, $priced['discounts']->coupons);
            foreach ($order->discounts as $discount) {
                if ($discount->automatic && !\in_array($discount->code, $kept, true)) {
                    $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The automatic discount %s no longer applies to the items, so the order lost it.', $discount->description === '' ? $discount->code : $discount->description)), $userId);
                }
            }

            return $this->orders->replaceItems($orderId, new NewOrderItems(
                $this->pricing->orderLines($priced),
                $this->pricing->shipping($priced, $pickupPoint),
                $priced['needs_shipping'] ? $shippingAddress : null,
                (string) $bill->total(),
                $bill->includesTax(),
                $this->pricing->exemption($priced),
                $this->pricing->taxProvider($priced),
            ), $userId);
        }));
    }

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    private function orderOnHold(int $orderId): OrderView
    {
        // In the words the customer read: kept answers stay theirs, and the shop's are kept beside them again.
        $order = $this->orders->inCustomerWords($orderId);
        if ($order->status !== 'on_hold') {
            throw new OrderRuleViolation('Only orders on hold can change their items; order #%1$d is %2$s.', 'orillacart_order_not_editable', values: [$orderId, str_replace('_', ' ', $order->status)]);
        }

        return $order;
    }

    /**
     * The order's codes, to price again, and its automatic discounts, the only ones that may apply again.
     *
     * @return array{list<string>, list<string>}
     */
    private static function discountsOf(OrderView $order): array
    {
        $codes = [];
        $automatic = [];
        foreach ($order->discounts as $discount) {
            if ($discount->automatic) {
                $automatic[] = $discount->code;
            } else {
                $codes[] = $discount->code;
            }
        }

        return [$codes, $automatic];
    }

    /** The order's items, unit prices and way to ship as they are. */
    private static function asItIs(OrderView $order): EditOrderItems
    {
        return new EditOrderItems(
            array_map(static fn(int $position, OrderLineView $line): EditedItem => new EditedItem($line->productId, $line->quantity, $line->optionIds, $line->unitPrice, $line->bundleChoices, $position), array_keys($order->lines), $order->lines),
            $order->shippingOption,
            $order->pickupPointId,
        );
    }

    /**
     * The shop's and add-ons' changes to the order's prices, as pricing takes them again: one for a kept item at
     * its new place, one for the whole order with its whole amount, each with its key. An item no longer kept
     * loses its own; the add-ons' order adjusters work theirs out again.
     *
     * @param array<int, int> $kept the new place of each kept item, by its place in the order
     *
     * @return list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}>
     */
    private static function changesOf(OrderView $order, array $kept): array
    {
        $changes = [];
        foreach ($order->priceChanges as $change) {
            if (!$change->removable || ($change->line !== null && !isset($kept[$change->line]))) {
                continue;
            }
            $changes[] = ['kind' => $change->kind, 'source' => $change->source, 'label' => $change->label, 'amount' => $change->amount, 'line' => $change->line === null ? null : $kept[$change->line], 'reason' => $change->reason, 'change' => $change->change];
        }

        return $changes;
    }
}
