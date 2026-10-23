<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AdjustOrderPrice;
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
use OrillaCart\Ordering\Domain\PriceAdjustment;
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
        $billing = new TaxAddress($order->billing->country, $order->billing->state, $order->billing->postcode, $order->billing->city, $order->billing->street);
        $shippingAddress = $order->shippingAddress ?? $order->billing;
        $items = array_map(static fn(OrderLineView $line): array => ['product_id' => $line->productId, 'option_ids' => $line->optionIds, 'bundle_choices' => $line->bundleChoices, 'quantity' => $line->quantity, 'unit_price' => $line->unitPrice, 'personalisation' => $line->personalisation], $order->lines);

        $codes = array_map(static fn(OrderDiscountView $discount): string => $discount->code, $order->discounts);

        return $this->pricing->price($items, new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode, $shippingAddress->city, $shippingAddress->street), $billing, $order->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge)['options'];
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
            throw new CheckoutRuleViolation(\sprintf('Order #%d has no item %d.', $orderId, $command->line + 1), 'orillacart_invalid_price_change');
        }
        $changes = self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines)));
        $changes[] = [
            'kind' => $kind,
            'source' => $source ?? ($userId === null ? 'shop' : (string) $userId),
            'label' => trim($command->label),
            'amount' => $command->amount,
            'line' => $command->line,
            'reason' => trim($command->reason) === '' ? null : trim($command->reason),
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
     * @throws OrderNotFound
     * @throws OrderRuleViolation    for an order not on hold
     * @throws CheckoutRuleViolation for a change the order does not have, or one that is not the shop's or an add-on's
     */
    public function removeAdjustment(int $orderId, int $adjustmentId, ?int $userId): OrderView
    {
        $order = $this->orderOnHold($orderId);
        $gone = null;
        foreach ($order->lines as $position => $line) {
            foreach ($line->adjustments as $adjustment) {
                if ($adjustment->id === $adjustmentId) {
                    $gone = [$adjustment, $position];
                }
            }
        }
        if ($gone === null || !\in_array($gone[0]->kind, [PriceAdjustment::MANUAL, PriceAdjustment::ADD_ON], true)) {
            throw new CheckoutRuleViolation('The order has no such change to its price; coupons go with their codes.', 'orillacart_unknown_price_change');
        }
        [$removed, $position] = $gone;
        $changes = array_values(array_filter(
            self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines))),
            static fn(array $change): bool => !($change['kind'] === $removed->kind && $change['source'] === $removed->source && $change['label'] === $removed->label && $change['reason'] === $removed->reason
                && ($removed->scope === PriceAdjustment::ORDER ? $change['line'] === null : $change['line'] === $position)),
        ));
        $this->reprice($order, self::asItIs($order), $changes, $userId);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The change "%s" to the price was taken off.', $removed->label)), $userId);

        return $this->orders->order($orderId);
    }

    /**
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string}> $changes
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
        $codes = array_map(static fn(OrderDiscountView $discount): string => $discount->code, $order->discounts);

        return $this->transactions->run(function () use ($order, $orderId, $items, $shipping, $billing, $command, $shippingAddress, $userId, $keptPoint, $codes, $changes): OrderView {
            $this->orders->releaseStock($orderId);
            // Its own uses do not count against the limits while it is priced again.
            $this->discounts->release($orderId);
            $priced = $this->pricing->price($items, $shipping, $billing, $command->shippingOption, $shippingAddress->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge, $order->paymentGateway, changes: $changes);
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

    /**
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    private function orderOnHold(int $orderId): OrderView
    {
        $order = $this->orders->order($orderId);
        if ($order->status !== 'on_hold') {
            throw new OrderRuleViolation(\sprintf('Only orders on hold can change their items; order #%d is %s.', $orderId, str_replace('_', ' ', $order->status)), 'orillacart_order_not_editable');
        }

        return $order;
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
     * its new place, one for the whole order once with its whole amount. An item no longer kept loses its own.
     *
     * @param array<int, int> $kept the new place of each kept item, by its place in the order
     *
     * @return list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string}>
     */
    private static function changesOf(OrderView $order, array $kept): array
    {
        /** @var list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, id: int}> $changes */
        $changes = [];
        // An order-wide change keeps the place it first had, with its shares added up again.
        $whole = [];
        /** @var array<int, Money> $sums */
        $sums = [];
        foreach ($order->lines as $position => $line) {
            foreach ($line->adjustments as $adjustment) {
                if (!\in_array($adjustment->kind, [PriceAdjustment::MANUAL, PriceAdjustment::ADD_ON], true)) {
                    continue;
                }
                if ($adjustment->scope === PriceAdjustment::LINE) {
                    if (isset($kept[$position])) {
                        $changes[] = ['kind' => $adjustment->kind, 'source' => $adjustment->source, 'label' => $adjustment->label, 'amount' => $adjustment->amount, 'line' => $kept[$position], 'reason' => $adjustment->reason, 'id' => $adjustment->id ?? 0];
                    }
                    continue;
                }
                $key = implode("\0", [$adjustment->kind, $adjustment->source, $adjustment->label, $adjustment->reason ?? '']);
                if (!isset($whole[$key])) {
                    $whole[$key] = \count($changes);
                    $changes[] = ['kind' => $adjustment->kind, 'source' => $adjustment->source, 'label' => $adjustment->label, 'amount' => '0', 'line' => null, 'reason' => $adjustment->reason, 'id' => $adjustment->id ?? 0];
                }
                $sums[$whole[$key]] = ($sums[$whole[$key]] ?? Money::zero())->add(Money::of($adjustment->amount));
            }
        }
        foreach ($sums as $index => $sum) {
            $changes[$index] = ['amount' => (string) $sum] + $changes[$index];
        }
        // In the order they were made: the order's records of them come in that order.
        usort($changes, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_map(static fn(array $change): array => ['kind' => $change['kind'], 'source' => $change['source'], 'label' => $change['label'], 'amount' => $change['amount'], 'line' => $change['line'], 'reason' => $change['reason']], $changes);
    }
}
