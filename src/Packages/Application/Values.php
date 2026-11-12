<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Catalog\AttributeDraft;
use OrillaCart\Extension\Catalog\AttributeSetSummary;
use OrillaCart\Extension\Catalog\AttributeSummary;
use OrillaCart\Extension\Catalog\CategorySummary;
use OrillaCart\Extension\Catalog\ProductDraft;
use OrillaCart\Extension\Catalog\ProductSummary;
use OrillaCart\Extension\Catalog\ProductVariationSummary;
use OrillaCart\Extension\Catalog\VariationDraft;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Customer\CustomerSummary;
use OrillaCart\Extension\Customer\NewCustomer;
use OrillaCart\Extension\Order\NewOrderItem;
use OrillaCart\Extension\Order\NewOrderRequest;
use OrillaCart\Extension\Order\OrderAddress;
use OrillaCart\Extension\Order\OrderLine;
use OrillaCart\Extension\Order\OrderQuote;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\Order\ShippingChoice;
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
            'shipping_option' => $order->shippingOption,
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
            'variations' => array_map(self::variation(...), $product->variations),
            'published' => $product->published,
            'attributes' => array_map(self::attribute(...), $product->attributes),
        ];
    }

    /** @return array<string, mixed> */
    public static function variation(ProductVariationSummary $variation): array
    {
        return [
            'id' => $variation->id,
            'label' => $variation->label,
            'sku' => $variation->sku,
            'price' => Money::of($variation->price),
            'regular_price' => $variation->regularPrice === null ? null : Money::of($variation->regularPrice),
            'for_sale' => $variation->forSale,
            'available' => $variation->available,
        ];
    }

    /** @return array{id: int, name: string, required: bool, options: list<array{id: int, name: string}>} */
    public static function attribute(AttributeSummary $attribute): array
    {
        return ['id' => $attribute->id, 'name' => $attribute->name, 'required' => $attribute->required, 'options' => array_map(static fn(int $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($attribute->options), array_values($attribute->options))];
    }

    /** @return array<string, mixed> */
    public static function attributeSet(AttributeSetSummary $set): array
    {
        return ['id' => $set->id, 'name' => $set->name, 'active' => $set->active, 'attributes' => array_map(self::attribute(...), $set->attributes)];
    }

    /** @return array<string, mixed> */
    public static function category(CategorySummary $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'parent_id' => $category->parentId, 'path' => $category->path, 'products' => $category->products];
    }

    /** What a product map may give, for shop.products.create and change (design 69). */
    public const array PRODUCT_KEYS = ['name', 'price', 'type', 'sku', 'sale_price', 'description', 'short_description', 'categories', 'tags', 'stock', 'stockrooms', 'stock_mode', 'weight', 'featured', 'attributes', 'attribute_set_ids'];

    /** What a variation map may give, for shop.products.add_variation and change_variation. */
    public const array VARIATION_KEYS = ['options', 'price', 'sku', 'sale_price', 'stock'];

    /**
     * @param array<mixed> $map
     *
     * @throws ScriptError for a key the map may not have, or a value of the wrong kind
     */
    public static function productDraft(array $map): ProductDraft
    {
        self::only($map, self::PRODUCT_KEYS, 'A product');
        $stockrooms = null;
        if (isset($map['stockrooms'])) {
            if (!\is_array($map['stockrooms'])) {
                throw new ScriptError('stockrooms is a map of stockroom id → level: { "2": 5 }.');
            }
            $stockrooms = [];
            foreach ($map['stockrooms'] as $room => $level) {
                $stockrooms[(int) $room] = self::whole($level, 'stockrooms');
            }
        }

        return new ProductDraft(
            name: self::optionalText($map['name'] ?? null),
            price: self::amount($map['price'] ?? null),
            type: self::optionalText($map['type'] ?? null),
            sku: self::optionalText($map['sku'] ?? null),
            salePrice: \array_key_exists('sale_price', $map) ? (self::amount($map['sale_price']) ?? '') : null,
            description: self::optionalText($map['description'] ?? null),
            shortDescription: self::optionalText($map['short_description'] ?? null),
            categories: self::texts($map, 'categories'),
            tags: self::texts($map, 'tags'),
            stock: isset($map['stock']) ? self::whole($map['stock'], 'stock') : null,
            stockrooms: $stockrooms,
            stockMode: self::optionalText($map['stock_mode'] ?? null),
            weight: self::optionalText($map['weight'] ?? null),
            featured: isset($map['featured']) ? $map['featured'] === true : null,
            attributes: isset($map['attributes']) ? self::attributeDrafts($map['attributes']) : null,
            attributeSetIds: isset($map['attribute_set_ids']) ? array_map(static fn(mixed $id): int => self::whole($id, 'attribute_set_ids'), \is_array($map['attribute_set_ids']) ? array_values($map['attribute_set_ids']) : throw new ScriptError('attribute_set_ids is a list of ids.')) : null,
        );
    }

    /**
     * @param array<mixed> $map
     *
     * @throws ScriptError
     */
    public static function variationDraft(array $map): VariationDraft
    {
        self::only($map, self::VARIATION_KEYS, 'A variation');
        $options = [];
        if (isset($map['options'])) {
            if (!\is_array($map['options'])) {
                throw new ScriptError('options is a map of attribute → option: { Colour: "Red" }.');
            }
            foreach ($map['options'] as $attribute => $option) {
                $options[(string) $attribute] = self::text($option);
            }
        }

        return new VariationDraft(
            $options,
            self::amount($map['price'] ?? null),
            self::optionalText($map['sku'] ?? null),
            \array_key_exists('sale_price', $map) ? (self::amount($map['sale_price']) ?? '') : null,
            isset($map['stock']) ? self::whole($map['stock'], 'stock') : null,
        );
    }

    /**
     * `[{ name, options: [..], required }]` as attribute drafts.
     *
     * @return list<AttributeDraft>
     *
     * @throws ScriptError
     */
    public static function attributeDrafts(mixed $list): array
    {
        if (!\is_array($list) || !array_is_list($list)) {
            throw new ScriptError('attributes is a list: [{ name: "Colour", options: ["Red", "Blue"], required: true }].');
        }

        return array_map(static function (mixed $attribute): AttributeDraft {
            if (!\is_array($attribute) || !\is_array($attribute['options'] ?? null)) {
                throw new ScriptError('An attribute is { name, options: [..], required }.');
            }
            self::only($attribute, ['name', 'options', 'required'], 'An attribute');

            return new AttributeDraft(self::text($attribute['name'] ?? ''), array_values(array_map(self::text(...), $attribute['options'])), ($attribute['required'] ?? false) === true);
        }, $list);
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $keys
     */
    private static function only(array $map, array $keys, string $what): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($map)), $keys);
        if ($unknown !== []) {
            throw new ScriptError(\sprintf('%s has no "%s": it takes %s.', $what, implode('", "', $unknown), implode(', ', $keys)));
        }
    }

    /**
     * @param array<mixed> $map
     *
     * @return list<string>|null
     */
    private static function texts(array $map, string $key): ?array
    {
        if (!isset($map[$key])) {
            return null;
        }
        if (!\is_array($map[$key])) {
            throw new ScriptError(\sprintf('%s is a list of names.', $key));
        }

        return array_values(array_map(self::text(...), $map[$key]));
    }

    private static function whole(mixed $value, string $key): int
    {
        if (\is_int($value) || (\is_float($value) && floor($value) === $value) || (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }

        throw new ScriptError(\sprintf('%s is a whole number.', $key));
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
            'addresses_from_order' => $customer->addressesFromOrder,
        ];
    }

    /** What a customer map may give, for shop.customers.create. */
    public const array CUSTOMER_KEYS = ['email', 'first_name', 'last_name', 'billing', 'shipping_address', 'test'];

    /**
     * `shop.customers.create({ email, first_name, last_name, billing, shipping_address, test })` as a new customer;
     * the addresses are optional, each { first_name, last_name, street, city, postcode, country, state, phone }.
     *
     * @param array<mixed> $map
     *
     * @throws ScriptError for a key the map may not have, or an address that is not a map
     */
    public static function newCustomer(array $map): NewCustomer
    {
        self::only($map, self::CUSTOMER_KEYS, 'A customer');
        $address = static function (string $key) use ($map): ?OrderAddress {
            $value = $map[$key] ?? null;
            if ($value === null) {
                return null;
            }

            return \is_array($value) ? self::orderAddress($value) : throw new ScriptError(\sprintf('%s is an address: { first_name, last_name, street, city, postcode, country, state, phone }.', $key));
        };

        return new NewCustomer(self::text($map['email'] ?? ''), self::text($map['first_name'] ?? ''), self::text($map['last_name'] ?? ''), $address('billing'), $address('shipping_address'), Ops::truthy($map['test'] ?? false));
    }

    /** @return array<string, mixed> */
    public static function orderQuote(OrderQuote $quote): array
    {
        return [
            'needs_shipping' => $quote->needsShipping,
            'shipping_options' => array_map(static fn(ShippingChoice $choice): array => ['id' => $choice->id, 'carrier' => $choice->carrier, 'name' => $choice->name, 'price' => Money::of($choice->price), 'tax' => Money::of($choice->tax), 'total' => Money::of($choice->total), 'to_pickup_point' => $choice->toPickupPoint], $quote->shippingOptions),
            'shipping_option' => $quote->shippingOption,
            'payments' => $quote->payments,
            'subtotal' => Money::of($quote->subtotal),
            'shipping' => Money::of($quote->shipping),
            'tax' => Money::of($quote->tax),
            'total' => Money::of($quote->total),
            'currency' => $quote->currency,
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
