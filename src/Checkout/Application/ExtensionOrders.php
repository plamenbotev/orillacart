<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\QuoteOrder;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Extension\Order\CreatesOrders;
use OrillaCart\Extension\Order\NewOrderItem;
use OrillaCart\Extension\Order\NewOrderRequest;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderQuote;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\Order\ShippingChoice;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Orders add-ons place (design 61), as the shop places one by hand: the same pricing, stock and checks, and the
 * shop's own note on the order saying who took it.
 */
final readonly class ExtensionOrders implements CreatesOrders
{
    public function __construct(private ManualOrders $orders) {}

    public function create(NewOrderRequest $request): OrderSummary
    {
        if ($request->items === []) {
            throw new \InvalidArgumentException('An order has at least one item.');
        }
        $command = new CreateOrder(
            $request->email,
            self::address($request->billing),
            array_map(static fn(NewOrderItem $item): EditedItem => new EditedItem($item->productId, $item->quantity, $item->optionIds, $item->unitPrice), $request->items),
            $request->shippingAddress === null ? null : self::address($request->shippingAddress),
            $request->customerId,
            $request->shippingOption,
            $request->payment,
            $request->paid,
            $request->note,
            $request->emailCustomer,
            fields: $request->fields,
            test: $request->test,
        );
        try {
            return OrderSummaries::summary($this->orders->create($command, null));
        } catch (RuleViolation $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    public function quote(NewOrderRequest $request): OrderQuote
    {
        if ($request->items === []) {
            throw new \InvalidArgumentException('An order has at least one item.');
        }
        $shipping = $request->shippingAddress ?? $request->billing;
        try {
            $quote = $this->orders->quote(new QuoteOrder(
                array_map(static fn(NewOrderItem $item): EditedItem => new EditedItem($item->productId, $item->quantity, $item->optionIds, $item->unitPrice), $request->items),
                $request->billing->country,
                $request->billing->state,
                $shipping->country,
                $shipping->state,
                $shipping->postcode,
                $request->billing->postcode,
                $request->shippingOption,
                $request->customerId,
                $request->email === '' ? null : $request->email,
                $request->payment,
                fields: $request->fields,
            ));
        } catch (RuleViolation|NotFound $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        return new OrderQuote(
            $quote->needsShipping,
            array_map(static fn(ShippingChoiceView $choice): ShippingChoice => new ShippingChoice($choice->id, $choice->carrier, $choice->name, $choice->price, $choice->tax, $choice->total, $choice->toPickupPoint), $quote->shippingOptions),
            $quote->needsShipping ? $quote->shippingOption : null,
            array_map(static fn(PaymentOptionView $payment): string => $payment->gateway, $quote->paymentOptions),
            $quote->subtotal,
            $quote->shipping,
            (string) Money::of($quote->itemsTax)->add(Money::of($quote->shippingTax)),
            $quote->total,
            $quote->currency,
        );
    }

    private static function address(OrderAddress $address): AddressData
    {
        return new AddressData($address->firstName, $address->lastName, $address->street, $address->city, $address->postcode, $address->country, $address->phone, $address->state);
    }
}
