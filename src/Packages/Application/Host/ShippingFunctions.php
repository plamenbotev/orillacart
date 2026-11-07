<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Extension\Shipping\BookedShipment;
use OrillaCart\Extension\Shipping\Label;
use OrillaCart\Extension\Shipping\PickupPoint;
use OrillaCart\Extension\Shipping\Rate;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/** `shipping`: what a scripted shipping method answers with, the add-on API's own records. */
final readonly class ShippingFunctions implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('shipping.rate', static fn(Context $c, mixed $code, mixed $name, mixed $price, mixed $deliveryDays = null, mixed $toPickupPoint = false): Rate => new Rate(Values::text($code), Values::text($name), Values::amount($price) ?? '0', $deliveryDays === null ? null : (int) Values::text($deliveryDays), Ops::truthy($toPickupPoint)), 3, 5, 'shipping.rate(code, name, price, delivery_days?, to_pickup_point?): one way to ship, offered at checkout'),
            new HostFunction('shipping.pickup_point', static fn(Context $c, mixed $id, mixed $name, mixed $address, mixed $hours = ''): PickupPoint => new PickupPoint(Values::text($id), Values::text($name), Values::text($address), Values::text($hours)), 3, 4, 'shipping.pickup_point(id, name, address, hours?): an office or locker the customer may choose'),
            new HostFunction('shipping.booked', static function (Context $c, mixed $number, mixed $trackingUrl = null, mixed $parcels = []): BookedShipment {
                if (!\is_array($parcels)) {
                    throw new ScriptError('shipping.booked takes the parcel numbers as a list');
                }

                return new BookedShipment(Values::text($number), Values::optionalText($trackingUrl), array_map(Values::text(...), array_values($parcels)));
            }, 1, 3, 'shipping.booked(number, tracking_url?, parcels?): the shipment the courier booked'),
            new HostFunction('shipping.label', static fn(Context $c, mixed $content, mixed $mimeType = 'application/pdf'): Label => new Label(Values::text($content), Values::text($mimeType)), 1, 2, 'shipping.label(content, mime_type?): the label file\'s bytes'),
            new HostFunction('shipping.tracking', static function (Context $c, mixed $status, mixed $description = ''): Tracking {
                $status = Values::text($status);
                if (!\in_array($status, ['booked', 'on_the_way', 'delivered', 'returned'], true)) {
                    throw new ScriptError('shipping.tracking takes a status of booked, on_the_way, delivered or returned');
                }

                return new Tracking($status, Values::text($description));
            }, 1, 2, 'shipping.tracking(status, description?): where a shipment is: booked, on_the_way, delivered or returned'),
        ];
    }
}
