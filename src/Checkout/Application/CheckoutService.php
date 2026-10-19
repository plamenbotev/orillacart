<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Checkout\Application\Dto\CheckoutCouponView;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\CouponCode;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Customers\Application\Dto\RegisterAccount;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
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
                $details = new CheckoutDetails($at['country'], $at['state'], $at['country'], $at['state'], $details->shippingOption, $at['postcode']);
            }
        }
        $shipping = $details->shippingCountry === null ? null : new TaxAddress($details->shippingCountry, $details->shippingState);
        $billing = $details->billingCountry === null ? $shipping : new TaxAddress($details->billingCountry, $details->billingState);
        $contents = $this->contents($owner);
        $reverse = $this->vatNumbers->reverseCharge($details->billingCountry, $details->vatNumber);
        $priced = $this->pricing->price($contents->lines, $shipping, $billing, $details->shippingOption, $details->shippingPostcode, $contents->coupons, $owner->customerId, reverseCharge: $reverse->applies);
        $bill = $priced['bill'];
        $currency = $this->settings->current()->currency;
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

        return new CheckoutView(
            array_map(static fn(array $line): CheckoutLineView => new CheckoutLineView(
                $line['quote']->name,
                OrderPricing::options($line['quote']),
                $line['quantity'],
                $line['quote']->unitPrice,
                (string) $line['total'],
                (string) $line['tax'],
                $line['quote']->shownBackorder($line['quantity']),
                (string) $line['discount'],
                array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $line['quote']->contents),
            ), $priced['lines']),
            $priced['needs_shipping'],
            $priced['options'],
            $priced['chosen']?->id,
            !$bill->total()->isZero(),
            $bill->total()->isZero() ? [] : $this->paymentOptions->available($billing?->country, $currency),
            array_map(static fn(BilledTax $tax): CheckoutTaxView => new CheckoutTaxView($tax->name, $tax->percent, (string) $tax->items, (string) $tax->shipping, (string) $tax->total()), $bill->taxes()),
            (string) $bill->subtotal(),
            (string) $bill->itemsTax(),
            (string) $bill->shipping(),
            (string) $bill->shippingTax(),
            (string) $bill->total(),
            $currency,
            $this->customers->accountMode(),
            $pickupPoints = $this->pricing->pickupPoints($priced, $details->shippingCountry, $details->shippingPostcode),
            \in_array($details->pickupPoint, array_map(static fn(PickupPointView $point): string => $point->id, $pickupPoints), true) ? $details->pickupPoint : null,
            (string) $bill->discount(),
            $coupons,
            $this->fields->asked($priced['needs_shipping']),
            $bill->includesTax(),
            $this->vatNumbers->asked(),
            $reverse->applies,
            $reverse->message,
            $estimatedFor,
        );
    }

    /**
     * Where to estimate tax and shipping for a customer who has not entered an address.
     *
     * @return array{country: string, state: ?string, postcode: ?string}|null
     */
    private function estimateAt(CartOwner $owner): ?array
    {
        $address = $owner->customerId === null ? null : $this->customers->savedAddress($owner->customerId);
        if ($address !== null) {
            return ['country' => $address->country, 'state' => $address->state, 'postcode' => $address->postcode];
        }
        $shop = $this->settings->current();

        return $shop->shopCountry === null || $shop->shopCountry === '' ? null : ['country' => $shop->shopCountry, 'state' => $shop->shopState, 'postcode' => $shop->shopPostcode];
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
        $priced = $this->pricing->price($contents->lines, null, null, null, null, $codes, $owner->customerId);
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
     *                                    country the shop does not sell to, or a shop that only shows its catalog
     * @throws PaymentProviderUnavailable when the payment cannot start; the order is then cancelled
     */
    public function placeOrder(CartOwner $owner, PlaceOrder $command): PlacedOrderView
    {
        if ($this->settings->current()->catalogOnly) {
            throw new CheckoutRuleViolation('The shop does not take orders online.', 'orillacart_catalog_only');
        }
        $contents = $this->contents($owner);
        $billingAddress = $this->addresses->require($command->billing, 'billing');
        $shippingAddress = $command->shippingAddress === null ? $billingAddress : $this->addresses->require($command->shippingAddress, 'shipping');
        $billingTax = new TaxAddress($billingAddress->country, $billingAddress->state);
        $shippingTax = new TaxAddress($shippingAddress->country, $shippingAddress->state);

        $reverse = $this->vatNumbers->reverseCharge($billingAddress->country, $command->vatNumber);
        $priced = $this->pricing->price($contents->lines, $shippingTax, $billingTax, $command->shippingOption, $shippingAddress->postcode, $contents->coupons, $owner->customerId, $command->email, reverseCharge: $reverse->applies);
        foreach ($priced['discounts']->refused as $code => $reason) {
            throw new CheckoutRuleViolation(\sprintf('The code %s does not apply: %s Remove it to order.', $code, $reason), 'orillacart_coupon_refused');
        }
        $bill = $priced['bill'];
        if ($command->expectedTotal !== null && !Money::of($command->expectedTotal)->equals($bill->total())) {
            // A price, a rule, a coupon or a rate changed since the summary: the customer orders only what they saw.
            throw new CheckoutRuleViolation('The total of your order changed. Review it and place the order again.', 'orillacart_cart_changed');
        }
        $chosen = $priced['chosen'];
        if ($priced['needs_shipping'] && ($chosen === null || $chosen->id !== $command->shippingOption)) {
            throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to this address.' : 'Choose how to ship the order.', 'orillacart_shipping_required');
        }
        $pickupPoint = $this->pricing->pickupPoint($priced, $shippingAddress->country, $shippingAddress->postcode, $command->pickupPoint);
        $currency = $this->settings->current()->currency;
        $needsPayment = !$bill->total()->isZero();
        $gateway = null;
        if ($needsPayment) {
            if ($command->payment === null || !$this->paymentOptions->isAvailable($command->payment, $billingAddress->country, $currency)) {
                throw new CheckoutRuleViolation('Choose how to pay.', 'orillacart_payment_required');
            }
            $gateway = $command->payment;
        }

        $answers = $this->fields->answers($command->fields, new FieldContext($command->email, $billingAddress->country, $priced['needs_shipping'] ? $shippingAddress->country : null, $owner->customerId), $priced['needs_shipping']);
        $registering = $this->registration($owner, $command);

        $order = $this->transactions->runRetrying(fn(): OrderView => $this->claimed($contents->cartId, fn(): OrderView => $this->redeemed($priced, $owner, $command->email, $this->orders->place(new NewOrder(
            $owner->customerId,
            $command->email,
            $billingAddress,
            $priced['needs_shipping'] ? $shippingAddress : null,
            $this->pricing->lines($priced),
            $this->pricing->shipping($priced, $pickupPoint),
            $this->pricing->taxLines($priced),
            (string) $bill->subtotal(),
            (string) $bill->itemsTax(),
            (string) $bill->total(),
            $currency,
            $gateway,
            $gateway === null ? '' : $this->paymentOptions->name($gateway),
            $gateway === null ? '' : $this->paymentOptions->receiptNote($gateway),
            $command->note,
            (string) $bill->discount(),
            $this->pricing->discounts($priced),
            $answers,
            $bill->includesTax(),
            $reverse->number,
            $reverse->applies,
            $this->languages->current(),
        )))));
        $receiptUrl = $this->links->receiptUrl($order->key);

        if ($gateway === null) {
            $this->orders->markPaid($order->number, null);
            $this->carts->clear($contents->cartId);
            $this->rememberCustomer($owner, $command, $registering, $order->number, $billingAddress, $priced['needs_shipping'] ? $shippingAddress : null);

            return $this->placed($order->key, $receiptUrl, null);
        }
        try {
            $payment = $this->payments->start(new StartPayment($order->number, $order->number, $gateway, $order->total, $currency, $receiptUrl));
        } catch (PaymentProviderUnavailable $e) {
            // The stock and the coupons' uses go back, and the customer keeps the cart to try again.
            $this->orders->cancel($order->number, 'The payment could not start.');
            $this->carts->notPlacing($contents->cartId);

            throw $e;
        }
        // Only now is the order the customer's to pay, and the cart done with.
        $this->carts->clear($contents->cartId);
        $this->rememberCustomer($owner, $command, $registering, $order->number, $billingAddress, $priced['needs_shipping'] ? $shippingAddress : null);
        if ($payment->isOffline()) {
            $this->orders->hold($order->number);
        }

        return $this->placed($order->key, $receiptUrl, $payment->redirectUrl);
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
     * Counts the coupons' uses for the order, in its transaction.
     *
     * @param array{discounts: AppliedDiscounts} $priced
     */
    private function redeemed(array $priced, CartOwner $owner, string $email, OrderView $order): OrderView
    {
        $this->discounts->redeem($order->number, $priced['discounts']->coupons, $owner->customerId, $email);

        return $order;
    }

    private function placed(string $key, string $receiptUrl, ?string $redirectUrl): PlacedOrderView
    {
        $order = $this->orders->byKey($key);

        return new PlacedOrderView($order->number, $order->key, $order->status, $order->total, $receiptUrl, $redirectUrl);
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
