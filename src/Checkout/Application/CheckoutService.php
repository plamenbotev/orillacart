<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Checkout\Application\Dto\CheckoutCouponView;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutFeeView;
use OrillaCart\Checkout\Application\Dto\CheckoutGiftCardView;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\Bill;
use OrillaCart\Checkout\Domain\BilledCharge;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\GiftCards\Application\Dto\GiftCardCodeInput;
use OrillaCart\GiftCards\Application\Dto\GiftCardPlan;
use OrillaCart\GiftCards\Application\Dto\GiftCardUse;
use OrillaCart\GiftCards\Application\GiftCardPayments;
use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Domain\SignUpSource;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\DeliveryDays;
use OrillaCart\Shipping\Application\Dto\DeliveryChoiceView;
use OrillaCart\Shipping\Application\Dto\DeliveryDayView;
use OrillaCart\Shipping\Application\Dto\DeliverySlotView;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\VatNumbers;
use Psr\Log\LoggerInterface;

/**
 * Turns a cart into an order: prices it with the customer's addresses (taxes, shipping), offers the ways to
 * ship and pay, and places the order: stock is taken and the order recorded together, then payment starts.
 *
 */
final readonly class CheckoutService
{
    public function __construct(
        private CartService $carts,
        private OrderPricing $pricing,
        private PaymentOptions $paymentOptions,
        private Payments $payments,
        private OrderService $orders,
        private OrderLinks $links,
        private SettingsService $settings,
        private AddressRules $addresses,
        private Transactions $transactions,
        private CustomerService $customers,
        private LoggerInterface $logger,
        private Discounts $discounts,
        private CheckoutFields $fields,
        private VatNumbers $vatNumbers,
        private Languages $languages,
        private ExtensionFailures $failures,
        private OrderFees $fees,
        private DeliveryDays $deliveryDays,
        private AddressChecks $addressChecks,
        private Newsletter $newsletter,
        private GiftCardPayments $giftCards,
        private Currencies $currencies,
        private ShownPrices $shownPrices,
    ) {}

    /**
     * @throws CheckoutRuleViolation for an empty cart, or one with items that cannot be ordered as they are
     */
    public function summary(CartOwner $owner, CheckoutDetails $details): CheckoutView
    {
        // Without an address, as in the cart: an estimate for the customer's saved address, or the shop's.
        $estimatedFor = null;
        if ($details->shippingCountry === null && $details->billingCountry === null) {
            $at = $this->estimateAt($owner);
            if ($at !== null) {
                $estimatedFor = $at['country'];
                $details = new CheckoutDetails($at['country'], $at['state'], $at['country'], $at['state'], $details->shippingOption, $at['postcode'], payment: $details->payment, billingPostcode: $at['postcode'], shippingCity: $at['city'], billingCity: $at['city']);
            }
        }
        $shipping = $details->shippingCountry === null ? null : new TaxAddress($details->shippingCountry, $details->shippingState, $details->shippingPostcode, $details->shippingCity);
        $billing = $details->billingCountry === null ? $shipping : new TaxAddress($details->billingCountry, $details->billingState, $details->billingPostcode ?? ($details->billingCountry === $details->shippingCountry ? $details->shippingPostcode : null), $details->billingCity ?? ($details->billingCountry === $details->shippingCountry ? $details->shippingCity : null));
        $contents = $this->contents($owner);
        $reverse = $this->vatNumbers->reverseCharge($details->billingCountry, $details->vatNumber);
        $currency = $this->currencies->current();
        // The methods offered depend on where the order is billed, not on what it costs: the one chosen, or the
        // first, prices its fee in.
        $methods = $this->paymentOptions->available($billing?->country, $currency);
        $gateways = array_map(static fn(PaymentOptionView $method): string => $method->gateway, $methods);
        $payment = \in_array($details->payment, $gateways, true) ? $details->payment : ($gateways[0] ?? null);
        $priced = $this->pricing->price($contents->lines, $shipping, $billing, $details->shippingOption, $details->shippingPostcode, $contents->coupons, $owner->customerId, reverseCharge: $reverse->applies, payment: $payment, currency: $currency);
        $bill = $priced['bill'];
        $plan = $this->giftCards->plan($contents->giftCards, $owner->customerId, $contents->useStoreCredit, $bill->total(), $currency);
        $due = $bill->total()->subtract($plan->total());
        $base = $bill->subtotal()->subtract($bill->discount())->add($bill->shipping());
        $applied = [];
        foreach ($priced['discounts']->coupons as $coupon) {
            $applied[$coupon->code] = new CheckoutCouponView($coupon->code, $coupon->description, (string) Money::of($coupon->amount), $coupon->freeShipping, null, $coupon->automatic);
        }
        $automatic = array_values(array_filter($applied, static fn(CheckoutCouponView $coupon): bool => $coupon->automatic));
        $coupons = array_map(
            static fn(string $code): CheckoutCouponView => $applied[Coupon::normalize($code)] ?? new CheckoutCouponView(Coupon::normalize($code), '', '0', false, $priced['discounts']->refused[Coupon::normalize($code)] ?? 'This code is not valid.'),
            $contents->coupons,
        );
        $coupons = [...$automatic, ...$coupons];
        // The amounts shown with tax or without, as the shop chose for the cart and checkout; the totals stay.
        $includesTax = $bill->includesTax();
        $withTax = $this->shownPrices->cartShowsTax();
        $lines = array_map(static fn(array $line): array => [$line, ...ShownPrices::line($line['quote']->unitPrice, $line['quantity'], (string) $line['total'], (string) $line['discount'], (string) $line['tax'], $includesTax, $withTax)], $priced['lines']);
        $subtotal = $withTax === $includesTax ? $bill->subtotal() : array_reduce($lines, static fn(Money $sum, array $line): Money => $sum->add(Money::of($line[2])), Money::zero());
        $discount = $withTax === $includesTax ? $bill->discount() : array_reduce($lines, static fn(Money $sum, array $line): Money => $sum->add(Money::of($line[3])), Money::zero());

        return new CheckoutView(
            array_map(static function (array $shown): CheckoutLineView {
                [$line, $unitPrice, $total, $discount] = $shown;

                return new CheckoutLineView(
                    $line['quote']->name,
                    OrderPricing::options($line['quote']),
                    $line['quantity'],
                    $unitPrice,
                    $total,
                    (string) $line['tax'],
                    $line['quote']->shownBackorder($line['quantity']),
                    $discount,
                    array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $line['quote']->contents),
                    $line['gift'],
                    array_map(static fn(array $answer): array => ['label' => $answer['label'], 'answer' => $answer['answer']], $line['personalisation']),
                );
            }, $lines),
            $priced['needs_shipping'],
            $priced['options'],
            $priced['chosen']?->id,
            !$due->isZero(),
            $due->isZero() ? [] : $methods,
            array_map(static fn(BilledTax $tax): CheckoutTaxView => new CheckoutTaxView($tax->name, $tax->percent, (string) $tax->items, (string) $tax->shipping, (string) $tax->total()), $bill->taxes()),
            (string) $subtotal,
            (string) $bill->itemsTax(),
            (string) ShownPrices::amount((string) $bill->shipping(), (string) $bill->shippingTax(), $includesTax, $withTax),
            (string) $bill->shippingTax(),
            (string) $bill->total(),
            $currency,
            $this->customers->accountMode(),
            $pickupPoints = $this->pricing->pickupPoints($priced, $details->shippingCountry, $details->shippingPostcode),
            \in_array($details->pickupPoint, array_map(static fn(PickupPointView $point): string => $point->id, $pickupPoints), true) ? $details->pickupPoint : null,
            (string) $discount,
            $coupons,
            $this->fields->asked(array_map(static fn(array $line): ItemQuote => $line['quote'], $priced['lines']), $priced['needs_shipping']),
            $withTax,
            $this->vatNumbers->asked(),
            $reverse->applies,
            $reverse->message,
            $estimatedFor,
            $priced['free_from'] === null ? null : (string) Money::of($priced['free_from'])->subtract($bill->subtotal()->subtract($bill->discount())),
            $due->isZero() ? null : $payment,
            array_map(static fn(BilledCharge $fee): CheckoutFeeView => new CheckoutFeeView($fee->label, (string) ShownPrices::amount((string) $fee->amount, (string) $fee->tax(), $includesTax, $withTax)), $bill->charges(BilledCharge::FEE)),
            (string) $bill->feesTax(),
            $due->isZero() ? [] : $this->paymentFees($gateways, $base, $currency),
            $delivery = $priced['needs_shipping'] ? $this->deliveryDays->forOption($priced['chosen']?->id) : null,
            ...self::deliveryChosen($delivery, $details->deliveryDate, $details->deliverySlot),
            ...$this->minimum($bill, $currency),
            shipsFrom: $priced['ships_from'],
            giftCards: self::giftCardViews($contents->giftCards, $plan),
            giftCardTotal: (string) $plan->total(),
            amountDue: (string) $due,
            storeCredit: $plan->storeCredit,
            usingStoreCredit: $contents->useStoreCredit && array_any($plan->uses, static fn(GiftCardUse $use): bool => $use->code === null),
            importScheme: $priced['import']->scheme,
            importCharges: $priced['import']->applies() && !$priced['import']->charges,
        );
    }

    /**
     * The codes as entered, each with what it pays or why it cannot, and the store credit when it pays.
     *
     * @param list<string> $codes
     *
     * @return list<CheckoutGiftCardView>
     */
    private static function giftCardViews(array $codes, GiftCardPlan $plan): array
    {
        $views = [];
        foreach ($codes as $code) {
            $use = array_find($plan->uses, static fn(GiftCardUse $use): bool => $use->code === $code);
            if ($use !== null) {
                $views[] = new CheckoutGiftCardView($code, $use->label, $use->amount, $use->balance);
            } elseif (isset($plan->refused[$code])) {
                $views[] = new CheckoutGiftCardView($code, $code, '0', refused: $plan->refused[$code]);
            }
        }
        foreach ($plan->uses as $use) {
            if ($use->code === null) {
                $views[] = new CheckoutGiftCardView(null, $use->label, $use->amount, $use->balance);
            }
        }

        return $views;
    }

    /**
     * Enters a gift card code for the order, once it is one that can pay.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a code that cannot pay
     */
    public function applyGiftCard(CartOwner $owner, GiftCardCodeInput $command): CheckoutView
    {
        $contents = $this->contents($owner);
        $code = strtoupper(trim($command->code));
        try {
            $balance = $this->giftCards->balance($code, $this->currencies->current());
        } catch (GiftCardRuleViolation $e) {
            throw new CheckoutRuleViolation($e->getMessage(), 'orillacart_gift_card_refused', $e);
        }
        if ($balance->refused !== null) {
            throw new CheckoutRuleViolation($balance->refused, 'orillacart_gift_card_refused');
        }
        if (!\in_array($code, $contents->giftCards, true) && \count($contents->giftCards) >= GiftCardPayments::MAX_CODES) {
            throw new CheckoutRuleViolation(\sprintf('An order takes up to %d gift cards.', GiftCardPayments::MAX_CODES), 'orillacart_gift_card_refused');
        }
        $this->carts->useGiftCards($contents->cartId, array_values(array_unique([...$contents->giftCards, $code])), $contents->useStoreCredit);

        return $this->summary($owner, new CheckoutDetails());
    }

    /**
     * @throws CheckoutRuleViolation for an empty cart
     */
    public function removeGiftCard(CartOwner $owner, string $code): CheckoutView
    {
        $contents = $this->contents($owner);
        $code = strtoupper(trim($code));
        $this->carts->useGiftCards($contents->cartId, array_values(array_filter($contents->giftCards, static fn(string $kept): bool => $kept !== $code)), $contents->useStoreCredit);

        return $this->summary($owner, new CheckoutDetails());
    }

    /**
     * The logged-in customer pays with their store credit, as far as it goes, or stops.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a visitor who is not logged in
     */
    public function useStoreCredit(CartOwner $owner, bool $use): CheckoutView
    {
        if ($owner->customerId === null) {
            throw new CheckoutRuleViolation('Log in to use your store credit.', 'orillacart_store_credit_login');
        }
        $contents = $this->contents($owner);
        $this->carts->useGiftCards($contents->cartId, $contents->giftCards, $use);

        return $this->summary($owner, new CheckoutDetails());
    }

    /**
     * The shop's least order and how much more the items need, when they come to less after discounts.
     *
     * @return array{minimumOrder: ?string, minimumShort: ?string}
     */
    private function minimum(Bill $bill, string $currency): array
    {
        $least = $this->settings->current()->minimumOrder;
        $minimum = $least === null ? null : $this->currencies->amount(Money::of($least), $currency);
        $items = $bill->subtotal()->subtract($bill->discount());
        if ($minimum === null || $items->compare($minimum) >= 0) {
            return ['minimumOrder' => null, 'minimumShort' => null];
        }

        return ['minimumOrder' => (string) $minimum, 'minimumShort' => (string) $minimum->subtract($items)];
    }

    /**
     * The day and time of day the form shows chosen: the customer's when they are offered, otherwise the first
     * day for a choice the customer must make.
     *
     * @return array{?string, ?string}
     */
    private static function deliveryChosen(?DeliveryChoiceView $delivery, ?string $date, ?string $slot): array
    {
        if ($delivery === null) {
            return [null, null];
        }
        $day = array_find($delivery->days, static fn(DeliveryDayView $day): bool => $day->date === $date)
            ?? ($delivery->required ? ($delivery->days[0] ?? null) : null);
        $open = array_values(array_filter($day->slots ?? [], static fn(DeliverySlotView $offered): bool => !$offered->full));
        $chosen = array_find($open, static fn(DeliverySlotView $offered): bool => $offered->id === $slot) ?? ($open[0] ?? null);

        return [$day?->date, $chosen?->id];
    }

    /**
     * What each way to pay adds to an order of this much, before tax, for the customer choosing.
     *
     * @param list<string> $gateways
     *
     * @return array<string, string> by gateway, those without a fee left out
     */
    private function paymentFees(array $gateways, Money $base, string $currency): array
    {
        $fees = [];
        foreach ($gateways as $gateway) {
            // Fees are the shop's: worked out on what the order is worth in its currency.
            $fee = $this->fees->paymentFee($gateway, $this->currencies->back($base, $currency));
            if ($fee !== null) {
                $fees[$gateway] = (string) $this->currencies->amount($fee, $currency)->rounded($this->currencies->decimals($currency));
            }
        }

        return $fees;
    }

    /**
     * Where to estimate tax and shipping for a customer who has not entered an address.
     *
     * @return array{country: string, state: ?string, postcode: ?string, city: ?string}|null
     */
    private function estimateAt(CartOwner $owner): ?array
    {
        $address = $owner->customerId === null ? null : $this->customers->savedAddress($owner->customerId);
        if ($address !== null) {
            return ['country' => $address->country, 'state' => $address->state, 'postcode' => $address->postcode, 'city' => $address->city];
        }
        $shop = $this->settings->current();

        return $shop->shopCountry === null || $shop->shopCountry === '' ? null : ['country' => $shop->shopCountry, 'state' => $shop->shopState, 'postcode' => $shop->shopPostcode, 'city' => null];
    }

    /**
     * Adds a coupon code to the cart when it holds for what the cart has; rules about the address or the customer
     * wait for checkout.
     *
     * @throws CheckoutRuleViolation for an empty cart, or a code that does not hold, saying why
     */
    public function applyCoupon(CartOwner $owner, CouponCode $command): CheckoutView
    {
        $contents = $this->contents($owner);
        $code = Coupon::normalize($command->code);
        $codes = array_values(array_unique([...array_map(Coupon::normalize(...), $contents->coupons), $code]));
        $priced = $this->pricing->price($contents->lines, null, null, null, null, $codes, $owner->customerId, currency: $this->currencies->current());
        $refused = $priced['discounts']->refused[$code] ?? null;
        if ($refused !== null) {
            throw new CheckoutRuleViolation($refused, 'orillacart_coupon_refused');
        }
        $this->carts->useCoupons($contents->cartId, $codes);

        return $this->summary($owner, new CheckoutDetails());
    }

    /**
     * @throws CheckoutRuleViolation for an empty cart
     */
    public function removeCoupon(CartOwner $owner, string $code): CheckoutView
    {
        $contents = $this->contents($owner);
        $code = Coupon::normalize($code);
        $this->carts->useCoupons($contents->cartId, array_values(array_filter(array_map(Coupon::normalize(...), $contents->coupons), static fn(string $kept): bool => $kept !== $code)));

        return $this->summary($owner, new CheckoutDetails());
    }

    /**
     * @throws CheckoutRuleViolation      for an empty cart, items that cannot be ordered, a missing or unknown
     *                                    shipping rate or payment method, stock that ran out, an address in a
     *                                    country the shop does not sell to, a shop that only shows its catalog,
     *                                    or a price an add-on that fails closed could not set
     * @throws PaymentProviderUnavailable when the payment cannot start; the order is then cancelled
     */
    public function placeOrder(CartOwner $owner, PlaceOrder $command): PlacedOrderView
    {
        if ($this->settings->current()->catalogOnly) {
            throw new CheckoutRuleViolation('The shop does not take orders online.', 'orillacart_catalog_only');
        }
        $failed = $this->failures->mark();
        $contents = $this->contents($owner);
        $billingAddress = $this->addresses->require($command->billing, 'billing');
        $shippingAddress = $command->shippingAddress === null ? $billingAddress : $this->addresses->require($command->shippingAddress, 'shipping');
        $billingTax = new TaxAddress($billingAddress->country, $billingAddress->state, $billingAddress->postcode, $billingAddress->city, $billingAddress->street);
        $shippingTax = new TaxAddress($shippingAddress->country, $shippingAddress->state, $shippingAddress->postcode, $shippingAddress->city, $shippingAddress->street);

        $reverse = $this->vatNumbers->reverseCharge($billingAddress->country, $command->vatNumber);
        $currency = $this->currencies->current();
        $priced = $this->pricing->price($contents->lines, $shippingTax, $billingTax, $command->shippingOption, $shippingAddress->postcode, $contents->coupons, $owner->customerId, $command->email, reverseCharge: $reverse->applies, payment: $command->payment, currency: $currency);
        foreach ($priced['discounts']->refused as $code => $reason) {
            throw new CheckoutRuleViolation(\sprintf('The code %s does not apply: %s Remove it to order.', $code, $reason), 'orillacart_coupon_refused');
        }
        if ($this->failures->closedSince($failed)) {
            throw new CheckoutRuleViolation('Some prices cannot be worked out right now. Try again in a moment.', 'orillacart_prices_unavailable');
        }
        $bill = $priced['bill'];
        if ($command->expectedTotal !== null && !Money::of($command->expectedTotal)->equals($bill->total())) {
            // A price, a rule, a coupon or a rate changed since the summary: the customer orders only what they saw.
            throw new CheckoutRuleViolation('The total of your order changed. Review it and place the order again.', 'orillacart_cart_changed');
        }
        ['minimumOrder' => $minimum] = $this->minimum($bill, $currency);
        if ($minimum !== null) {
            throw new CheckoutRuleViolation(\sprintf('Orders start at %s %s. Add more items to order.', Money::of($minimum)->format($this->currencies->decimals($currency)), $currency), 'orillacart_below_minimum_order');
        }
        $chosen = $priced['chosen'];
        if ($priced['needs_shipping'] && ($chosen === null || $chosen->id !== $command->shippingOption)) {
            if ($priced['options'] === [] && $priced['ships_from'] !== null) {
                throw new CheckoutRuleViolation(\sprintf('Delivery to this address needs an order of at least %s %s.', Money::of($priced['ships_from'])->format($this->currencies->decimals($currency)), $currency), 'orillacart_shipping_required');
            }
            throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to this address.' : 'Choose how to ship the order.', 'orillacart_shipping_required');
        }
        $pickupPoint = $this->pricing->pickupPoint($priced, $shippingAddress->country, $shippingAddress->postcode, $command->pickupPoint);
        try {
            $delivery = $priced['needs_shipping'] ? $this->deliveryDays->pick($chosen?->id, $command->deliveryDate, $command->deliverySlot) : null;
        } catch (ShippingRuleViolation $e) {
            throw new CheckoutRuleViolation($e->getMessage(), $e->errorCode, $e);
        }
        $plan = $this->giftCards->plan($contents->giftCards, $owner->customerId, $contents->useStoreCredit, $bill->total(), $currency);
        foreach ($plan->refused as $code => $reason) {
            throw new CheckoutRuleViolation(\sprintf('The gift card %s cannot pay: %s Remove it to order.', $code, $reason), 'orillacart_gift_card_refused');
        }
        $needsPayment = !$bill->total()->subtract($plan->total())->isZero();
        $gateway = null;
        if ($needsPayment) {
            if ($command->payment === null || !$this->paymentOptions->isAvailable($command->payment, $billingAddress->country, $currency)) {
                throw new CheckoutRuleViolation('Choose how to pay.', 'orillacart_payment_required');
            }
            $gateway = $command->payment;
        }

        $answers = $this->fields->answers($command->fields, new FieldContext($command->email, $billingAddress->country, $priced['needs_shipping'] ? $shippingAddress->country : null, $owner->customerId), array_map(static fn(array $line): ItemQuote => $line['quote'], $priced['lines']), $priced['needs_shipping']);
        $registering = $this->registration($owner, $command);

        $order = $this->transactions->runRetrying(fn(): OrderView => $this->claimed($contents->cartId, fn(): OrderView => $this->redeemed($priced, $plan, $owner, $command->email, $this->orders->place(new NewOrder(
            $owner->customerId,
            $command->email,
            $billingAddress,
            $priced['needs_shipping'] ? $shippingAddress : null,
            $this->pricing->orderLines($priced),
            $this->pricing->shipping($priced, $pickupPoint),
            (string) $bill->total(),
            $currency,
            $gateway,
            $gateway === null ? '' : $this->paymentOptions->name($gateway),
            $gateway === null ? '' : $this->paymentOptions->receiptNote($gateway),
            $command->note,
            $answers,
            $bill->includesTax(),
            $reverse->number,
            $reverse->applies,
            $this->languages->current(),
            $delivery?->date,
            $delivery?->slot,
            $delivery?->key,
            $this->pricing->exemption($priced),
            $this->pricing->taxProvider($priced),
            $priced['import']->scheme,
            $priced['import']->charges ? $priced['import']->number : null,
            $currency === $this->settings->current()->currency ? null : $this->settings->current()->currency,
            $currency === $this->settings->current()->currency ? null : $this->currencies->rate($currency),
            $command->buyerReference,
        )), $gateway === null)));
        foreach ([...$this->failures->since($failed), ...$this->addressChecks->kept($billingAddress, $priced['needs_shipping'] && $command->shippingAddress !== null ? $shippingAddress : null)] as $failure) {
            $this->orders->note($order->number, $failure, false);
        }
        $receiptUrl = $this->links->receiptUrl($order->number);

        if ($gateway === null) {
            $this->carts->clear($contents->cartId, $order->number, $order->total);
            $this->rememberCustomer($owner, $command, $registering, $order->number, $billingAddress, $priced['needs_shipping'] ? $shippingAddress : null);

            return $this->placed($order->number, $receiptUrl, null);
        }
        try {
            $payment = $this->payments->start(StartPayment::forOrder($order, $gateway));
        } catch (PaymentProviderUnavailable $e) {
            // The stock and the coupons' uses go back, and the customer keeps the cart to try again.
            $this->orders->cancel($order->number, 'The payment could not start.');
            $this->carts->notPlacing($contents->cartId);

            throw $e;
        }
        // Only now is the order the customer's to pay, and the cart done with.
        $this->carts->clear($contents->cartId, $order->number, $order->total);
        $this->rememberCustomer($owner, $command, $registering, $order->number, $billingAddress, $priced['needs_shipping'] ? $shippingAddress : null);
        if ($payment->isOffline()) {
            $this->orders->hold($order->number);
        }

        return $this->placed($order->number, $receiptUrl, $payment->redirectUrl);
    }

    /**
     * Whether the order makes the customer an account, refused before anything is placed when it cannot.
     *
     * @throws CheckoutRuleViolation when the shop requires an account
     * @throws RuleViolation         for an email that has an account, or a short password
     */
    private function registration(CartOwner $owner, PlaceOrder $command): bool
    {
        if ($owner->customerId !== null) {
            return false;
        }
        if ($command->createAccount && $this->customers->accountsOffered()) {
            return true;
        }
        if ($this->customers->accountsRequired()) {
            throw new CheckoutRuleViolation('Log in or make an account to order.', 'orillacart_account_required');
        }

        return false;
    }

    private function rememberCustomer(CartOwner $owner, PlaceOrder $command, bool $registering, int $orderId, AddressData $billing, ?AddressData $shippingAddress): void
    {
        if ($registering) {
            // The order is a guest's until the customer follows the emailed link; the account then has it.
            try {
                $this->customers->requestAccount(new RegisterAccount($command->email, $billing->firstName, $billing->lastName), $orderId);
            } catch (RuleViolation $e) {
                $this->logger->warning('No account was asked for with order {order}: {reason}', ['order' => $orderId, 'reason' => $e->getMessage()]);
            }
        } elseif ($owner->customerId !== null && $command->rememberAddresses) {
            $this->customers->rememberAddresses($owner->customerId, $billing, $shippingAddress);
        }
        if ($command->newsletter && $this->newsletter->offer() !== null) {
            $this->newsletter->subscribe($command->email, $billing->firstName, $billing->lastName, SignUpSource::Checkout, $owner->customerId);
        }
    }

    /**
     * Places the order from the cart under the cart's lock, in the placement's transaction: a second submit of
     * the same checkout waits for the first and is then refused instead of placing the order twice.
     *
     * @param \Closure(): OrderView $place
     *
     * @param-immediately-invoked-callable $place
     *
     * @throws CheckoutRuleViolation while an order is being placed from the cart
     */
    private function claimed(int $cartId, \Closure $place): OrderView
    {
        if ($this->carts->lockForOrder($cartId) !== null) {
            throw new CheckoutRuleViolation('This order is already being placed. Wait a moment, then look at your email or your account.', 'orillacart_order_in_progress');
        }
        $order = $place();
        $this->carts->placing($cartId, $order->number);

        return $order;
    }

    /**
     * Counts the coupons' uses for the order, and takes what gift cards pay, in its transaction.
     *
     * @param array{discounts: AppliedDiscounts} $priced
     */
    private function redeemed(array $priced, GiftCardPlan $plan, CartOwner $owner, string $email, OrderView $order, bool $free = false): OrderView
    {
        $this->discounts->redeem($order->number, $priced['discounts']->coupons, $owner->customerId, $email);
        if ($plan->uses !== []) {
            try {
                $this->giftCards->pay($order->number, $plan, $order->currency);
            } catch (GiftCardRuleViolation $e) {
                throw new CheckoutRuleViolation($e->getMessage(), $e->errorCode, $e);
            }
            // As the payment will be asked for: the rest the gift cards left.
            $order = $this->orders->order($order->number);
        }
        if ($free) {
            // Nothing to pay: paid with the order, in its transaction, so a free order is never left unpaid.
            $this->orders->markPaid($order->number, null);
        }

        return $order;
    }

    private function placed(int $number, string $receiptUrl, ?string $redirectUrl): PlacedOrderView
    {
        $order = $this->orders->order($number);

        return new PlacedOrderView($order->number, $order->status, $order->total, $receiptUrl, $redirectUrl);
    }

    private function contents(CartOwner $owner): CartContents
    {
        $contents = $this->carts->contents($owner) ?? throw new CheckoutRuleViolation('The cart is empty.', 'orillacart_cart_empty');
        if ($contents->refusals !== []) {
            throw new CheckoutRuleViolation(implode(' ', $contents->refusals), 'orillacart_cart_refused');
        }

        return $contents;
    }
}
