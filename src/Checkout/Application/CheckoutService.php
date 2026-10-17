<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutLineView;
use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\Bill;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\NewOrder;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Payment\Application\Payments;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\TaxCalculator;

/**
 * Turns a cart into an order: prices it with the customer's addresses (taxes, shipping), offers the ways to
 * ship and pay, and places the order: stock is taken and the order recorded together, then payment starts.
 *
 * @phpstan-type PricedLine array{cart_line: int, quote: ItemQuote, quantity: int, total: Money, tax: Money}
 * @phpstan-type Priced array{lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView}
 */
final readonly class CheckoutService
{
    public function __construct(
        private CartService $carts,
        private ProductQuotes $quotes,
        private TaxCalculator $tax,
        private ShippingQuotes $shipping,
        private PaymentOptions $paymentOptions,
        private Payments $payments,
        private OrderService $orders,
        private OrderLinks $links,
        private StockService $stock,
        private SettingsService $settings,
        private GeographyService $geography,
        private Transactions $transactions,
    ) {}

    /**
     * @throws CheckoutRuleViolation for an empty cart, or one with items that cannot be ordered as they are
     */
    public function summary(CartOwner $owner, CheckoutDetails $details): CheckoutView
    {
        $shipping = $details->shippingCountry === null ? null : new TaxAddress($details->shippingCountry, $details->shippingState);
        $billing = $details->billingCountry === null ? $shipping : new TaxAddress($details->billingCountry, $details->billingState);
        $priced = $this->price($this->contents($owner), $shipping, $billing, $details->rateId);
        $bill = $priced['bill'];
        $currency = $this->settings->current()->currency;

        return new CheckoutView(
            array_map(static fn(array $line): CheckoutLineView => new CheckoutLineView(
                $line['quote']->name,
                self::options($line['quote']),
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

        $priced = $this->price($contents, $shippingTax, $billingTax, $command->rateId);
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

        $order = $this->transactions->run(fn(): OrderView => $this->orders->place(new NewOrder(
            $owner->customerId,
            $command->email,
            $billingAddress,
            $priced['needs_shipping'] ? $shippingAddress : null,
            array_map(fn(array $line): NewOrderLine => new NewOrderLine(
                $line['quote']->productId,
                $line['quote']->variationId,
                array_map(static fn(QuotedOption $option): int => $option->id, $line['quote']->options),
                self::options($line['quote']),
                $line['quote']->name,
                $line['quote']->sku,
                $line['quantity'],
                $line['quote']->unitPrice,
                (string) $line['total'],
                (string) $line['tax'],
                $line['quote']->type === 'physical',
                $this->take($line['quote'], $line['quantity']),
            ), $priced['lines']),
            $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->rateId, $chosen->carrier, $chosen->name, (string) $bill->shipping(), (string) $bill->shippingTax()),
            array_map(static fn(BilledTax $tax): TaxLineView => new TaxLineView($tax->name, $tax->percent, (string) $tax->total()), $bill->taxes()),
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
     * @return Priced
     */
    private function price(CartContents $contents, ?TaxAddress $shipping, ?TaxAddress $billing, ?int $rateId): array
    {
        $decimals = $this->settings->current()->priceDecimals;
        $bill = new Bill($decimals);
        $lines = [];
        $parcel = [];
        foreach ($contents->lines as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids']));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item in the cart is no longer sold. Review the cart and try again.', 'orillacart_cart_changed');
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation(\sprintf('%s is not available in the quantity in the cart. Review the cart and try again.', $quote->name), 'orillacart_cart_changed');
            }
            $total = Money::of($quote->unitPrice)->times($line['quantity']);
            $taxes = $quote->taxable ? $this->taxes($quote->taxGroupId, $shipping, $billing) : [];
            $lines[] = ['cart_line' => $line['line'], 'quote' => $quote, 'quantity' => $line['quantity'], 'total' => $total, 'tax' => $bill->addItem($total, $taxes)];
            if ($quote->type === 'physical') {
                $parcel[] = new ParcelItem($line['quantity'], $quote->weight, $quote->length, $quote->width, $quote->height);
            }
        }

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        if ($needsShipping) {
            $offered = $this->shipping->options(new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $bill->subtotal()));
            foreach ($offered as $option) {
                $taxes = $option->taxable ? $this->taxes($option->taxGroupId, $shipping, $billing) : [];
                $price = Money::of($option->price);
                $tax = array_reduce($taxes, static fn(Money $sum, Tax $rate): Money => $sum->add($price->percent($rate->percent)->rounded($decimals)), Money::zero());
                $view = new ShippingChoiceView($option->rateId, $option->carrier, $option->name, (string) $price->rounded($decimals), (string) $tax, (string) $price->rounded($decimals)->add($tax));
                $options[] = $view;
                // The customer's choice when it is offered, otherwise the first rate, so the totals include shipping.
                if ($chosen === null || $option->rateId === $rateId) {
                    $chosen = $chosen !== null && $chosen['view']->rateId === $rateId ? $chosen : ['view' => $view, 'price' => $price, 'taxes' => $taxes];
                }
            }
            if ($chosen !== null) {
                $bill->ship($chosen['price'], $chosen['taxes']);
            }
        }

        return ['lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null];
    }

    /**
     * @return list<Tax>
     */
    private function taxes(?int $groupId, ?TaxAddress $shipping, ?TaxAddress $billing): array
    {
        return array_map(
            static fn(AppliedTaxView $tax): Tax => new Tax($tax->rateId === null ? "{$tax->name}|{$tax->percent}" : (string) $tax->rateId, $tax->name, $tax->percent),
            $this->tax->taxes(new TaxQuery($groupId, $shipping, $billing))->taxes,
        );
    }

    /**
     * @return list<TakenStock>
     */
    private function take(ItemQuote $quote, int $quantity): array
    {
        $refs = $quote->variationId !== null
            ? [StockItemRef::variation($quote->variationId)]
            : [StockItemRef::product($quote->productId), ...array_map(static fn(QuotedOption $option): StockItemRef => StockItemRef::option($option->id), $quote->options)];
        // Stock that does not limit the item takes nothing; items always available or never sold were checked above.
        try {
            return $quote->available === null ? [] : $this->stock->take($refs, $quantity);
        } catch (InsufficientStock) {
            throw new CheckoutRuleViolation(\sprintf('%s sold out while you were ordering. Review the cart and try again.', $quote->name), 'orillacart_out_of_stock');
        }
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

    /**
     * @return list<array{attribute: string, option: string}>
     */
    private static function options(ItemQuote $quote): array
    {
        return array_map(static fn(QuotedOption $option): array => ['attribute' => $option->attribute, 'option' => $option->name], $quote->options);
    }
}
