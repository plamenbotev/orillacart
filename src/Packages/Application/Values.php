<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Payment\Notification;
use OrillaCart\Extension\Payment\PaymentReturn;
use OrillaCart\Extension\Payment\PaymentSession;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\ShipmentRequest;
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
