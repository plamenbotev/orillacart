<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\OffersPickupPoints;
use OrillaCart\Extension\Shipping\PickupPoint;
use OrillaCart\Extension\Shipping\PickupQuery;
use OrillaCart\Extension\Shipping\Rate;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/**
 * A `shipping_method "id" { … }` declaration as the shop's shipping contract. Its functions: `quote(shipment,
 * settings)` (required) answering a list of shipping.rate(), `is_ready(settings)`, `pickup_points(query,
 * settings)`; with `create_shipment` it is a ScriptedCourier that books, labels and tracks.
 */
class ScriptedShippingMethod extends ScriptedProvider implements ShippingMethod, OffersPickupPoints
{
    public function name(): string
    {
        return $this->title();
    }

    public function isReady(Settings $settings): bool
    {
        if (!$this->has('is_ready')) {
            return $this->missingSetting($settings) === null;
        }
        try {
            return Ops::truthy($this->call('is_ready', [self::values($settings)]));
        } catch (HandlerFailed) {
            return false;
        }
    }

    public function quote(Shipment $shipment, Settings $settings): array
    {
        try {
            $answer = $this->call('quote', [Values::shipment($shipment), self::values($settings)]);
        } catch (HandlerFailed) {
            // Refused: this method does not ship there.
            return [];
        }

        return $this->listOf($answer, Rate::class, 'quote');
    }

    public function pickupPoints(PickupQuery $query, Settings $settings): array
    {
        if (!$this->has('pickup_points')) {
            return [];
        }
        try {
            $answer = $this->call('pickup_points', [Values::pickupQuery($query), self::values($settings)]);
        } catch (HandlerFailed) {
            return [];
        }

        return $this->listOf($answer, PickupPoint::class, 'pickup_points');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function listOf(mixed $answer, string $class, string $function): array
    {
        $items = [];
        foreach (Ops::iterable($answer === null ? [] : $answer) as $item) {
            $checked = $this->expect($item, $class, $function);
            \assert($checked instanceof $class);
            $items[] = $checked;
        }

        return $items;
    }
}
