<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Geography\Application\GeographyService;
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
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Tax\Application\Dto\TaxAddress;
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
        private GeographyService $geography,
        private Transactions $transactions,
        private CustomerService $customers,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws CheckoutRuleViolation for an empty cart, or one with items that cannot be ordered as they are
     */
    public function summary(CartOwner $owner, CheckoutDetails $details): CheckoutView
    {
        $shipping = $details->shippingCountry === null ? null : new TaxAddress($details->shippingCountry, $details->shippingState);
        $billing = $details->billingCountry === null ? $shipping : new TaxAddress($details->billingCountry, $details->billingState);
        $priced = $this->pricing->price($this->contents($owner)->lines, $shipping, $billing, $details->rateId);
        $bill = $priced['bill'];
        $currency = $this->settings->current()->currency;

        return new CheckoutView(
            array_map(static fn(array $line): CheckoutLineView => new CheckoutLineView(
                $line['quote']->name,
                OrderPricing::options($line['quote']),
                $line['quantity'],
                $line['quote']->unitPrice,
                (string) $line['total'],
                (string) $line['tax'],
            ), $priced['lines']),
            $priced['needs_shipping'],
            $priced['options'],
            $priced['chosen']?->rateId,
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
        );
    }

    /**
     * @throws CheckoutRuleViolation      for an empty cart, items that cannot be ordered, a missing or unknown
     *                                    shipping rate or payment method, or stock that ran out
     * @throws PaymentProviderUnavailable when the payment cannot start; the order is then cancelled
     */
    public function placeOrder(CartOwner $owner, PlaceOrder $command): PlacedOrderView
    {
        $contents = $this->contents($owner);
        $billingAddress = $this->requireAddress($command->billing, 'billing');
        $shippingAddress = $command->shippingAddress === null ? $billingAddress : $this->requireAddress($command->shippingAddress, 'shipping');
        $billingTax = new TaxAddress($billingAddress->country, $billingAddress->state);
        $shippingTax = new TaxAddress($shippingAddress->country, $shippingAddress->state);

        $priced = $this->pricing->price($contents->lines, $shippingTax, $billingTax, $command->rateId);
        $bill = $priced['bill'];
        $chosen = $priced['chosen'];
        if ($priced['needs_shipping'] && ($chosen === null || $chosen->rateId !== $command->rateId)) {
            throw new CheckoutRuleViolation($priced['options'] === [] ? 'The order cannot be shipped to this address.' : 'Choose how to ship the order.', 'orillacart_shipping_required');
        }
        $currency = $this->settings->current()->currency;
        $needsPayment = !$bill->total()->isZero();
        $gateway = null;
        if ($needsPayment) {
            if ($command->payment === null || !$this->paymentOptions->isAvailable($command->payment, $billingAddress->country, $currency)) {
                throw new CheckoutRuleViolation('Choose how to pay.', 'orillacart_payment_required');
            }
            $gateway = $command->payment;
        }

        $registering = $this->registration($owner, $command);

        $order = $this->transactions->run(fn(): OrderView => $this->orders->place(new NewOrder(
            $owner->customerId,
            $command->email,
            $billingAddress,
            $priced['needs_shipping'] ? $shippingAddress : null,
            $this->pricing->lines($priced),
            $this->pricing->shipping($priced),
            $this->pricing->taxLines($priced),
            (string) $bill->subtotal(),
            (string) $bill->itemsTax(),
            (string) $bill->total(),
            $currency,
            $gateway,
            $gateway === null ? '' : $this->paymentOptions->name($gateway),
            $gateway === null ? '' : $this->paymentOptions->receiptNote($gateway),
            $command->note,
        )));
        $this->carts->clear($contents->cartId);
        $this->rememberCustomer($owner, $command, $registering, $order->number, $billingAddress, $priced['needs_shipping'] ? $shippingAddress : null);
        $receiptUrl = $this->links->receiptUrl($order->key);

        if ($gateway === null) {
            $this->orders->markPaid($order->number, null);

            return $this->placed($order->key, $receiptUrl, null);
        }
        try {
            $payment = $this->payments->start(new StartPayment($order->number, $order->number, $gateway, $order->total, $currency, $receiptUrl));
        } catch (PaymentProviderUnavailable $e) {
            $this->orders->cancel($order->number);

            throw $e;
        }
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
            $this->customers->checkRegistration($command->email, $command->password ?? '');

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
            try {
                $this->customers->registerAfterOrder($orderId, $command->email, $command->password ?? '', $billing, $shippingAddress);
            } catch (RuleViolation $e) {
                // Checked before ordering; someone took the email since. The order stands as a guest order.
                $this->logger->warning('No account was made with order {order}: {reason}', ['order' => $orderId, 'reason' => $e->getMessage()]);
            }
        } elseif ($owner->customerId !== null && $command->rememberAddresses) {
            $this->customers->rememberAddresses($owner->customerId, $billing, $shippingAddress);
        }
    }

    private function placed(string $key, string $receiptUrl, ?string $redirectUrl): PlacedOrderView
    {
        $order = $this->orders->byKey($key);

        return new PlacedOrderView($order->number, $order->key, $order->status, $order->total, $receiptUrl, $redirectUrl);
    }

    private function contents(CartOwner $owner): CartContents
    {
        return $this->carts->contents($owner) ?? throw new CheckoutRuleViolation('The cart is empty.', 'orillacart_cart_empty');
    }




    /**
     * The address with a known country, and a state exactly when the country has states.
     */
    private function requireAddress(AddressData $address, string $kind): AddressData
    {
        try {
            $states = $this->geography->states($address->country);
            $this->geography->require($address->country, $states === [] ? null : $address->state);
        } catch (RuleViolation) {
            throw new CheckoutRuleViolation(\sprintf('The %s address has an unknown country or state.', $kind), 'orillacart_invalid_address');
        }
        if ($states !== [] && $address->state === null) {
            throw new CheckoutRuleViolation(\sprintf('Choose the state of the %s address.', $kind), 'orillacart_invalid_address');
        }

        return $states === [] && $address->state !== null
            ? new AddressData($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->phone)
            : $address;
    }

}
