<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\CheckoutFeeView;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\OrderQuoteView;
use OrillaCart\Checkout\Application\Dto\QuoteOrder;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * Orders the shop takes itself, as on the phone or in person: priced and stocked as checkout does, for a
 * customer's account or a guest, to any country, paid already or waiting for the payment.
 */
final readonly class ManualOrders
{
    public function __construct(
        private OrderPricing $pricing,
        private OrderService $orders,
        private PaymentOptions $paymentOptions,
        private AddressRules $addresses,
        private CustomerService $customers,
        private SettingsService $settings,
        private Transactions $transactions,
        private Discounts $discounts,
        private ExtensionFailures $failures,
    ) {}

    /**
     * @throws CheckoutRuleViolation for items not sold or not available in that quantity
     */
    public function quote(QuoteOrder $query): OrderQuoteView
    {
        $shipping = $query->shippingCountry === null ? null : new TaxAddress($query->shippingCountry, $query->shippingState, $query->shippingPostcode);
        $billing = $query->billingCountry === null ? $shipping : new TaxAddress($query->billingCountry, $query->billingState, $query->billingPostcode);
        $priced = $this->pricing->price(self::items($query->items), $shipping ?? $billing, $billing, $query->shippingOption, $query->shippingPostcode, [], $query->customerId, $query->email, payment: $query->payment);
        $bill = $priced['bill'];
        $currency = $this->settings->current()->currency;

        return new OrderQuoteView(
            array_map(static fn(array $line): CheckoutLineView => new CheckoutLineView(
                $line['quote']->name,
                OrderPricing::options($line['quote']),
                $line['quantity'],
                $line['unit_price'],
                (string) $line['total'],
                (string) $line['tax'],
                $line['quote']->shownBackorder($line['quantity']),
            ), $priced['lines']),
            $priced['needs_shipping'],
            $priced['options'],
            $priced['chosen']?->id,
            $this->paymentOptions->available($billing?->country, $currency),
            array_map(static fn(BilledTax $tax): CheckoutTaxView => new CheckoutTaxView($tax->name, $tax->percent, (string) $tax->items, (string) $tax->shipping, (string) $tax->total()), $bill->taxes()),
            (string) $bill->subtotal(),
            (string) $bill->itemsTax(),
            (string) $bill->shipping(),
            (string) $bill->shippingTax(),
            (string) $bill->total(),
            $currency,
            $this->pricing->pickupPoints($priced, $query->shippingCountry ?? $query->billingCountry, $query->shippingPostcode),
            (string) $bill->discount(),
            $this->pricing->discounts($priced),
            array_map(static fn(array $fee): CheckoutFeeView => new CheckoutFeeView($fee['label'], (string) $fee['amount']), $bill->fees()),
            (string) $bill->feesTax(),
        );
    }

    /**
     * @throws NotFound              for a customer that does not exist
     * @throws CheckoutRuleViolation for an unknown address, items that cannot be ordered, stock that ran out,
     *                               a missing shipping rate, a payment method the shop does not offer, or
     *                               a price an add-on that fails closed could not set
     */
    public function create(CreateOrder $command, ?int $userId): OrderView
    {
        if ($command->customerId !== null) {
            $this->customers->customer($command->customerId);
        }
        $billingAddress = $this->addresses->require($command->billing, 'billing', soldToCountry: false);
        $shippingAddress = $command->shippingAddress === null ? $billingAddress : $this->addresses->require($command->shippingAddress, 'shipping', soldToCountry: false);
        $currency = $this->settings->current()->currency;
        if ($command->payment !== null && !$this->paymentOptions->isAvailable($command->payment, $billingAddress->country, $currency)) {
            throw new CheckoutRuleViolation('The shop does not offer this payment method for the billing country.', 'orillacart_payment_required');
        }

        $failed = $this->failures->mark();
        $order = $this->transactions->run(function () use ($command, $billingAddress, $shippingAddress, $currency, $userId, $failed): OrderView {
            $priced = $this->pricing->price(
                self::items($command->items),
                new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode),
                new TaxAddress($billingAddress->country, $billingAddress->state, $billingAddress->postcode),
                $command->shippingOption,
                $shippingAddress->postcode,
                [],
                $command->customerId,
                $command->email,
                payment: $command->payment,
            );
            if ($this->failures->closedSince($failed)) {
                throw new CheckoutRuleViolation('Some prices cannot be worked out right now: an add-on that sets them failed. Try again in a moment.', 'orillacart_prices_unavailable');
            }
            if ($priced['needs_shipping'] && $priced['chosen']?->id !== $command->shippingOption) {
                throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to this address.' : 'Choose how the order ships.', 'orillacart_shipping_required');
            }
            $bill = $priced['bill'];
            $pickupPoint = $this->pricing->pickupPoint($priced, $shippingAddress->country, $shippingAddress->postcode, $command->pickupPoint);

            $order = $this->orders->place(new NewOrder(
                $command->customerId,
                $command->email,
                $billingAddress,
                $priced['needs_shipping'] ? $shippingAddress : null,
                $this->pricing->orderLines($priced),
                $this->pricing->shipping($priced, $pickupPoint),
                (string) $bill->total(),
                $currency,
                $command->payment,
                $command->payment === null ? '' : $this->paymentOptions->name($command->payment),
                $command->payment === null ? '' : $this->paymentOptions->receiptNote($command->payment),
                $command->note,
                [],
                $bill->includesTax(),
            ));
            // The shop's automatic discounts count their uses as at checkout; the shop enters no codes.
            $this->discounts->redeem($order->number, $priced['discounts']->coupons, $command->customerId, $command->email);
            // With the order, all or nothing: an order taken is never left without its note and its status.
            $this->orders->addNote($order->number, new AddOrderNote('Taken by the shop.'), $userId);
            foreach ($this->failures->since($failed) as $failure) {
                $this->orders->note($order->number, $failure, false);
            }
            if ($command->paid || Money::of($order->total)->isZero()) {
                $this->orders->markPaid($order->number, null, 'Paid when the shop took the order.', tellCustomer: $command->emailCustomer, tellShop: false);
            } else {
                $this->orders->hold($order->number, 'Waiting for the payment.', tellCustomer: $command->emailCustomer, tellShop: false);
            }

            return $order;
        });

        return $this->orders->order($order->number);
    }

    /**
     * @param list<EditedItem> $items
     *
     * @return list<array{product_id: int, option_ids: list<int>, bundle_choices: list<int>, quantity: int, unit_price: ?string}>
     */
    private static function items(array $items): array
    {
        return array_map(static fn(EditedItem $item): array => ['product_id' => $item->productId, 'option_ids' => $item->optionIds, 'bundle_choices' => $item->bundleChoices, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice], $items);
    }
}
