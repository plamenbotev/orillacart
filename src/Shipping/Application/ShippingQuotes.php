<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\Rate;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Destination;
use OrillaCart\Shipping\Domain\Measure;
use OrillaCart\Shipping\Domain\Parcel;
use OrillaCart\Shipping\Domain\ShippingClass;
use OrillaCart\Shipping\Domain\ShippingClassRepository;
use Psr\Log\LoggerInterface;

/**
 * Which rates can ship a parcel, priced for it: checkout's view of shipping. Taxes are checkout's to add.
 * A carrier priced by a shipping method gets its services from the method, kept for a few minutes; a method
 * that fails leaves the carrier out and is logged, so checkout still offers the others.
 */
final readonly class ShippingQuotes
{
    private const int KEEP_SECONDS = 600;

    public function __construct(
        private CarrierRepository $carriers,
        private ShippingClassRepository $classes,
        private ShippingMethods $methods,
        private SettingsService $settings,
        private RateCache $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<ShippingOptionView> active carriers in their order, each carrier's rates in theirs
     */
    public function options(ShippingQuery $query): array
    {
        $destination = $query->country === null ? null : new Destination($query->country, $query->state, $query->postcode);
        $measure = static fn(?string $value): Measure => $value === null || $value === '' ? Measure::zero() : Measure::of($value);
        $known = array_map(static fn(ShippingClass $class): int => $class->id(), $this->classes->all());
        $parcel = Parcel::of(array_map(static fn(ParcelItem $item): array => [
            'quantity' => $item->quantity,
            'weight' => $measure($item->weight),
            'length' => $measure($item->length),
            'width' => $measure($item->width),
            'height' => $measure($item->height),
            'class' => $item->shippingClassId !== null && \in_array($item->shippingClassId, $known, true) ? $item->shippingClassId : ShippingClass::NONE,
        ], $query->items), Money::of($query->subtotal));

        $options = [];
        foreach ($this->carriers->all() as $carrier) {
            if (!$carrier->isActive()) {
                continue;
            }
            if ($carrier->method() !== null) {
                foreach ($query->country === null ? [] : $this->quoted($carrier, $query) as $rate) {
                    $options[] = new ShippingOptionView(
                        "carrier:{$carrier->id()}:{$rate->code}",
                        $carrier->id(),
                        $carrier->name(),
                        $rate->name,
                        (string) Money::of($rate->price),
                        $carrier->isMethodTaxable(),
                        $carrier->methodTaxGroupId(),
                    );
                }
                continue;
            }
            foreach ($carrier->rates() as $rate) {
                if ($rate->accepts($destination, $parcel)) {
                    $options[] = new ShippingOptionView(
                        'rate:' . $rate->id(),
                        $carrier->id(),
                        $carrier->name(),
                        $rate->name(),
                        (string) $rate->priceFor($parcel),
                        $rate->details()->taxable,
                        $rate->details()->taxGroupId,
                    );
                }
            }
        }

        return $options;
    }

    /**
     * @return list<Rate> the services the carrier's method quotes, each once
     */
    private function quoted(Carrier $carrier, ShippingQuery $query): array
    {
        $method = $carrier->method() === null ? null : $this->methods->find($carrier->method());
        $settings = new Settings($carrier->methodSettings());
        if ($method === null || $query->country === null || !$method->isReady($settings)) {
            return [];
        }
        $shop = $this->settings->current();
        $shipment = new Shipment(
            $query->country,
            $query->state,
            $query->postcode,
            array_map(static fn(ParcelItem $item): ShipmentItem => new ShipmentItem($item->quantity, $item->weight, $item->length, $item->width, $item->height), $query->items),
            $query->subtotal,
            $shop->currency,
            $shop->weightUnit,
            $shop->dimensionUnit,
        );
        $key = "carrier:{$carrier->id()}:" . hash('sha256', serialize([$method->id(), $settings->all(), $shipment]));
        $kept = $this->cache->get($key);
        if ($kept !== null) {
            return array_map(static fn(array $row): Rate => new Rate($row['code'], $row['name'], $row['price'], $row['days']), $kept);
        }

        try {
            $quoted = $method->quote($shipment, $settings);
        } catch (\Throwable $e) {
            $this->logger->warning('{method} could not quote for carrier {carrier}; checkout leaves it out. {message}', ['method' => $method->name(), 'carrier' => $carrier->name(), 'message' => $e->getMessage()]);

            return [];
        }
        $rates = [];
        foreach ($quoted as $rate) {
            $rates[$rate->code] ??= $rate;
        }
        $rates = array_values($rates);
        $this->cache->put($key, array_map(static fn(Rate $rate): array => ['code' => $rate->code, 'name' => $rate->name, 'price' => $rate->price, 'days' => $rate->deliveryDays], $rates), self::KEEP_SECONDS);

        return $rates;
    }
}
