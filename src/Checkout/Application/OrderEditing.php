<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\AddOrderCharge;
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
use OrillaCart\Ordering\Domain\Charge;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\ReverseCharge;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\VatNumbers;

/**
 * The shop changes the items of an order that waits for an offline payment, as when the customer calls:
 * the order's stock is put back, the new items are priced as checkout prices them (with the shop's own unit
 * prices where it set them) and their stock is taken, all at once or not at all. The order keeps its coupons,
 * priced again; one that no longer holds is dropped, and the history says why.
 *
 * @phpstan-import-type Priced from OrderPricing
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
        private VatNumbers $vatNumbers,
    ) {}

    /**
     * The order priced again as checkout prices it: under its reverse charge for services, and for goods only
     * where they go to another EU country (VatNumbers::forGoodsTo()), not when they stay at home or leave the EU.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
     * @param list<string> $codes
     * @param list<string> $automatic
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<array{label: string, amount: string, quantity: int, taxable: bool, tax_group_id: ?int, source: string, given?: bool, for_product_ids?: list<int>}> $charges
     *
     * @return Priced
     */
    private function priced(OrderView $order, array $items, TaxAddress $shipping, TaxAddress $billing, ?string $shippingOption, array $codes, array $automatic, array $changes = [], array $charges = []): array
    {
        $price = fn(?bool $goods): array => $this->pricing->price($items, $shipping, $billing, $shippingOption, $shipping->postcode, $codes, $order->customerId, $order->email, $order->number, $order->reverseCharge, $order->paymentGateway, $order->currency, $changes, $automatic, $goods, charges: $charges);
        $priced = $price(null);
        $reverse = new ReverseCharge($order->reverseCharge, $order->vatNumber);
        $goods = $this->vatNumbers->forGoodsTo($reverse, $this->pricing->goodsTo($priced, $shipping));

        return $goods->applies === $reverse->applies ? $priced : $price($goods->applies);
    }

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

        return $this->priced($order, $items, new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode, $shippingAddress->city, $shippingAddress->street), $billing, $order->shippingOption, $codes, $automatic)['options'];
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
        // The note is the shop's: the item in its own words, not the customer's (design 51).
        $shopWords = $this->orders->order($orderId);
        $view = $this->reprice($order, self::asItIs($order), $changes, $userId);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf(
            '%s: %s %s%s%s.',
            trim($command->label),
            $command->amount,
            $order->currency,
            $command->line === null ? ' on the whole order' : \sprintf(' on %s', $shopWords->lines[$command->line]->name),
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
     * Adds a price of its own to an order on hold, the shop's: gift wrapping, so much each. It prices the order
     * again, its taxes too.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation for an order not on hold
     */
    public function addCharge(int $orderId, AddOrderCharge $command, ?int $userId): OrderView
    {
        $order = $this->orderOnHold($orderId);
        $charges = [...self::chargesOf($order), ['label' => trim($command->label), 'amount' => $command->amount, 'quantity' => $command->quantity, 'taxable' => $command->taxable, 'tax_group_id' => $command->taxGroupId, 'source' => Charge::SHOP, 'given' => true]];
        $this->reprice($order, self::asItIs($order), self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines))), $userId, $charges);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('%s added: %d × %s %s.', trim($command->label), $command->quantity, $command->amount, $order->currency)), $userId);

        return $this->orders->order($orderId);
    }

    /**
     * Takes a charge the shop added off an order on hold, by its place in the order; an add-on's stays.
     *
     * @throws OrderNotFound
     * @throws OrderRuleViolation    for an order not on hold
     * @throws CheckoutRuleViolation for a place that is not one of the shop's charges
     */
    public function removeCharge(int $orderId, int $position, ?int $userId): OrderView
    {
        $order = $this->orderOnHold($orderId);
        $removed = array_find($order->charges, static fn(OrderLineView $line): bool => $line->position === $position && ($line->charge['source'] ?? null) === Charge::SHOP);
        if ($removed === null) {
            throw new CheckoutRuleViolation('The order has no charge of the shop\'s there; an add-on\'s charges go with what they came with.', 'orillacart_unknown_charge');
        }
        $charges = self::chargesOf($order, $position);
        $this->reprice($order, self::asItIs($order), self::changesOf($order, array_combine(array_keys($order->lines), array_keys($order->lines))), $userId, $charges);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('%s was taken off.', $removed->name)), $userId);

        return $this->orders->order($orderId);
    }

    /**
     * The order's charges as pricing takes them again: so much each as the customer pays it, but the one at the
     * place left out.
     *
     * @return list<array{label: string, amount: string, quantity: int, taxable: bool, tax_group_id: ?int, source: string, given?: bool, for_product_ids?: list<int>}>
     */
    private static function chargesOf(OrderView $order, ?int $without = null): array
    {
        $charges = [];
        foreach ($order->charges as $line) {
            if ($line->type === 'charge' && $line->charge !== null && $line->position !== $without) {
                $charges[] = ['label' => $line->name, 'amount' => $line->unitPrice, 'quantity' => $line->quantity, 'taxable' => $line->charge['taxable'], 'tax_group_id' => $line->charge['tax_group_id'], 'source' => $line->charge['source'], 'given' => true, 'for_product_ids' => $line->charge['for_product_ids']];
            }
        }

        return $charges;
    }

    /**
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<array{label: string, amount: string, quantity: int, taxable: bool, tax_group_id: ?int, source: string, given?: bool, for_product_ids?: list<int>}>|null $charges null for the order's own
     */
    private function reprice(OrderView $order, EditOrderItems $command, array $changes, ?int $userId, ?array $charges = null): OrderView
    {
        $charges ??= self::chargesOf($order);
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
        return $this->languages->in($order->locale, fn(): OrderView => $this->transactions->run(function () use ($order, $orderId, $items, $shipping, $billing, $command, $shippingAddress, $userId, $keptPoint, $codes, $automatic, $changes, $charges): OrderView {
            $this->orders->releaseStock($orderId);
            // Its own uses do not count against the limits while it is priced again.
            $this->discounts->release($orderId);
            // In the order's currency, with its own automatic discounts: not ones the shop started since.
            $priced = $this->priced($order, $items, $shipping, $billing, $command->shippingOption, $codes, $automatic, $changes, $charges);
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
