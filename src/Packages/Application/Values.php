<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Catalog\ProductSummary;
use OrillaCart\Extension\Catalog\ProductVariationSummary;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Customer\CustomerSummary;
use OrillaCart\Extension\Order\NewOrderItem;
use OrillaCart\Extension\Order\NewOrderRequest;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderLine;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Pricing\AdjustmentQuery;
use OrillaCart\Extension\Pricing\PriceQuery;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\ShipmentRequest;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Shared\Domain\Money;

/**
 * The add-on API's records as a script sees them: maps with snake_case keys, amounts as money. Read-only by
 * nature, as the script gets a copy.
 */
final class Values
{
    /** @return array<string, mixed> */
    public static function session(PaymentSession $session): array
    {
        return [
            'payment_id' => $session->paymentId,
            'order_number' => $session->orderNumber,
            'amount' => Money::of($session->amount),
            'currency' => $session->currency,
            'return_url' => $session->returnUrl,
            'cancel_url' => $session->cancelUrl,
            'notification_url' => $session->notificationUrl,
            'items' => array_map(static fn($item): array => ['name' => $item->name, 'sku' => $item->sku, 'quantity' => $item->quantity, 'unit_amount' => Money::of($item->unitAmount)], $session->items),
            'breakdown' => $session->breakdown === null ? null : [
                'item_total' => Money::of($session->breakdown->itemTotal),
                'tax' => Money::of($session->breakdown->tax),
                'shipping' => Money::of($session->breakdown->shipping),
                'discount' => Money::of($session->breakdown->discount),
                'handling' => Money::of($session->breakdown->handling),
            ],
            'ship_to' => $session->shipTo === null ? null : [
                'name' => $session->shipTo->name,
                'street' => $session->shipTo->street,
                'city' => $session->shipTo->city,
                'postcode' => $session->shipTo->postcode,
                'country' => $session->shipTo->country,
                'state' => $session->shipTo->state,
            ],
            'email' => $session->email,
        ];
    }

    /** @return array<string, mixed> */
    public static function notification(Notification $notification): array
    {
        $headers = [];
        foreach ($notification->headers as $name => $value) {
            $headers[strtolower((string) $name)] = \is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
        }
        $json = json_decode($notification->body, true);

        return ['headers' => $headers, 'body' => $notification->body, 'json' => \is_array($json) ? $json : null, 'query' => $notification->query];
    }

    /** @return array<string, mixed> */
    public static function paymentReturn(PaymentReturn $return): array
    {
        return ['query' => $return->query, 'cancelled' => $return->cancelled];
    }

    /** @return array<string, mixed> */
    public static function refund(RefundRequest $request): array
    {
        return [
            'id' => $request->id,
            'order_number' => $request->orderNumber,
            'reference' => $request->reference,
            'transaction_id' => $request->transactionId,
            'amount' => Money::of($request->amount),
            'currency' => $request->currency,
            'reason' => $request->reason,
        ];
    }

    /** @return array<string, string|bool> */
    public static function settings(Settings $settings): array
    {
        return $settings->all();
    }

    /** @return array<string, mixed> */
    public static function shipment(Shipment $shipment): array
    {
        return [
            'country' => $shipment->country,
            'state' => $shipment->state,
            'postcode' => $shipment->postcode,
            'items' => array_map(static fn(ShipmentItem $item): array => ['quantity' => $item->quantity, 'weight' => $item->weight, 'length' => $item->length, 'width' => $item->width, 'height' => $item->height, 'name' => $item->name], $shipment->items),
            'subtotal' => Money::of($shipment->subtotal),
            'currency' => $shipment->currency,
            'weight_unit' => $shipment->weightUnit,
            'dimension_unit' => $shipment->dimensionUnit,
            'weight' => $shipment->weight(),
        ];
    }

    /** @return array<string, mixed> */
    public static function pickupQuery(PickupQuery $query): array
    {
        return ['service' => $query->service, 'country' => $query->country, 'postcode' => $query->postcode, 'city' => $query->city];
    }

    /** @return array<string, mixed> */
    public static function shipmentRequest(ShipmentRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'service' => $request->service,
            'recipient_name' => $request->recipientName,
            'phone' => $request->phone,
            'email' => $request->email,
            'street' => $request->street,
            'city' => $request->city,
            'postcode' => $request->postcode,
            'country' => $request->country,
            'state' => $request->state,
            'pickup_point_id' => $request->pickupPointId,
            'contents' => self::shipment($request->contents),
            'cash_on_delivery' => $request->cashOnDelivery === null ? null : Money::of($request->cashOnDelivery),
            'declared_value' => Money::of($request->declaredValue),
            'parcels' => $request->parcels,
        ];
    }

    /** @return array<string, mixed> */
    public static function order(OrderSummary $order): array
    {
        return [
            'number' => $order->number,
            'status' => $order->status,
            'placed_at' => $order->placedAt->format(\DATE_ATOM),
            'paid_at' => $order->paidAt?->format(\DATE_ATOM),
            'email' => $order->email,
            'customer_id' => $order->customerId,
            'billing' => self::address($order->billing),
            'shipping_address' => $order->shippingAddress === null ? null : self::address($order->shippingAddress),
            'lines' => array_map(static fn(OrderLine $line): array => [
                'product_id' => $line->productId,
                'variation_id' => $line->variationId,
                'sku' => $line->sku,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unit_price' => Money::of($line->unitPrice),
                'total' => Money::of($line->total),
                'tax' => Money::of($line->tax),
                'personalisation' => $line->personalisation,
                'type' => $line->type,
                'add_on_type' => $line->addOnType,
                'adjustments' => array_map(static fn(array $adjustment): array => ['amount' => Money::of($adjustment['amount'])] + $adjustment, $line->adjustments),
            ], $order->lines),
            'subtotal' => Money::of($order->subtotal),
            'discount' => Money::of($order->discount),
            'shipping_total' => Money::of($order->shippingTotal),
            'tax' => Money::of($order->tax),
            'total' => Money::of($order->total),
            'refunded' => Money::of($order->refunded),
            'currency' => $order->currency,
            'payment' => $order->payment,
            'transaction_id' => $order->transactionId,
            'shipping' => $order->shipping,
            'note' => $order->note,
            'fields' => $order->fields,
            'fees' => self::record($order->fees),
            'test' => $order->test,
        ];
    }

    /** @return array<string, mixed> */
    public static function product(ProductSummary $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'sku' => $product->sku,
            'price' => Money::of($product->price),
            'regular_price' => $product->regularPrice === null ? null : Money::of($product->regularPrice),
            'for_sale' => $product->forSale,
            'available' => $product->available,
            'on_backorder' => $product->onBackorder,
            'variations' => array_map(static fn(ProductVariationSummary $variation): array => [
                'id' => $variation->id,
                'label' => $variation->label,
                'sku' => $variation->sku,
                'price' => Money::of($variation->price),
                'regular_price' => $variation->regularPrice === null ? null : Money::of($variation->regularPrice),
                'for_sale' => $variation->forSale,
                'available' => $variation->available,
            ], $product->variations),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(CustomerSummary $customer): array
    {
        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'first_name' => $customer->firstName,
            'last_name' => $customer->lastName,
            'registered_at' => $customer->registeredAt->format(\DATE_ATOM),
            'billing' => $customer->billing === null ? null : self::address($customer->billing),
            'shipping_address' => $customer->shippingAddress === null ? null : self::address($customer->shippingAddress),
            'group_ids' => $customer->groupIds,
            'orders' => $customer->orders,
        ];
    }

    /**
     * `shop.orders.create({ email, customer_id, billing: { first_name, … }, shipping_address, items: [{ product_id,
     * quantity, option_ids, unit_price }], shipping_option, payment, paid, note, fields })` as a request.
     *
     * @param array<mixed> $map
     *
     * @throws ScriptError for a map that is not an order
     */
    public static function orderRequest(array $map): NewOrderRequest
    {
        $billing = $map['billing'] ?? null;
        if (!\is_array($billing)) {
            throw new ScriptError('An order has a billing address: { first_name, last_name, street, city, postcode, country, state, phone }.');
        }
        $items = [];
        foreach (\is_array($map['items'] ?? null) ? $map['items'] : [] as $item) {
            if (!\is_array($item)) {
                throw new ScriptError('An order\'s items are maps: { product_id, quantity, option_ids, unit_price }.');
            }
            $optionIds = \is_array($item['option_ids'] ?? null) ? array_map(static fn(mixed $id): int => (int) self::text($id), array_values($item['option_ids'])) : [];
            $items[] = new NewOrderItem((int) self::text($item['product_id'] ?? 0), (int) self::text($item['quantity'] ?? 1), $optionIds, self::amount($item['unit_price'] ?? null));
        }
        $fields = [];
        foreach (\is_array($map['fields'] ?? null) ? $map['fields'] : [] as $key => $value) {
            $fields[(string) $key] = \is_bool($value) || \is_int($value) ? $value : self::text($value);
        }
        $shipping = $map['shipping_address'] ?? null;

        return new NewOrderRequest(
            self::text($map['email'] ?? ''),
            self::orderAddress($billing),
            $items,
            \is_array($shipping) ? self::orderAddress($shipping) : null,
            isset($map['customer_id']) && $map['customer_id'] !== '' ? (int) self::text($map['customer_id']) : null,
            self::optionalText($map['shipping_option'] ?? null),
            self::optionalText($map['payment'] ?? null),
            Ops::truthy($map['paid'] ?? false),
            self::text($map['note'] ?? ''),
            $fields,
            Ops::truthy($map['email_customer'] ?? false),
            Ops::truthy($map['test'] ?? false),
        );
    }

    /** @param array<mixed> $map */
    private static function orderAddress(array $map): OrderAddress
    {
        return new OrderAddress(self::text($map['first_name'] ?? ''), self::text($map['last_name'] ?? ''), self::text($map['street'] ?? ''), self::text($map['city'] ?? ''), self::text($map['postcode'] ?? ''), self::text($map['country'] ?? ''), self::optionalText($map['state'] ?? null), self::text($map['phone'] ?? ''));
    }

    /** @return array<string, mixed> */
    public static function address(OrderAddress $address): array
    {
        return ['first_name' => $address->firstName, 'last_name' => $address->lastName, 'street' => $address->street, 'city' => $address->city, 'postcode' => $address->postcode, 'country' => $address->country, 'state' => $address->state, 'phone' => $address->phone];
    }

    /** @return array<string, mixed> */
    public static function cart(CheckedCart $cart): array
    {
        return [
            'customer_id' => $cart->customerId,
            'currency' => $cart->currency,
            'lines' => array_map(static fn(CheckedLine $line): array => ['id' => $line->id, 'product_id' => $line->productId, 'variation_id' => $line->variationId, 'sku' => $line->sku, 'name' => $line->name, 'quantity' => $line->quantity, 'unit_price' => Money::of($line->unitPrice)], $cart->lines),
        ];
    }

    /** @return array<string, mixed> */
    public static function feeQuery(FeeQuery $query): array
    {
        return [
            'items' => self::record($query->items),
            'subtotal' => Money::of($query->subtotal),
            'shipping' => Money::of($query->shipping),
            'shipping_option' => $query->shippingOption,
            'payment_method' => $query->paymentMethod,
            'country' => $query->country,
            'customer_id' => $query->customerId,
            'currency' => $query->currency,
        ];
    }

    /** @return array<string, mixed> */
    public static function priceQuery(PriceQuery $query): array
    {
        return [
            'product_id' => $query->productId,
            'variation_id' => $query->variationId,
            'quantity' => $query->quantity,
            'customer_id' => $query->customerId,
            'price' => Money::of($query->price),
            'regular_price' => Money::of($query->regularPrice),
            'currency' => $query->currency,
        ];
    }

    /** @return array<string, mixed> */
    public static function adjustmentQuery(AdjustmentQuery $query): array
    {
        return ['lines' => self::record($query->lines), 'currency' => $query->currency, 'customer_id' => $query->customerId, 'email' => $query->email, 'order_number' => $query->orderNumber, 'fields' => $query->fields];
    }

    /**
     * Any of the add-on API's records as a map: public properties as snake_case keys, moments as ISO 8601 text,
     * records inside it the same way. For the records a script reads but the shop names no amounts in.
     */
    public static function record(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::record(...), $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }
        if ($value instanceof Money) {
            return $value;
        }
        if (\is_object($value)) {
            $map = [];
            foreach (new \ReflectionObject($value)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $map[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property->getName()))] = self::record($property->getValue($value));
            }

            return $map;
        }

        return $value;
    }

    /** A script's text or money as the add-on API's amount string. */
    public static function amount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Money ? $value->toFixed() : Money::of(\OrillaCart\Scripting\Domain\Ops::text($value))->toFixed();
    }

    public static function text(mixed $value): string
    {
        return \OrillaCart\Scripting\Domain\Ops::text($value);
    }

    public static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : \OrillaCart\Scripting\Domain\Ops::text($value);
    }
}
