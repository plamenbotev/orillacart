<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\ShippingOptionView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Destination;
use OrillaCart\Shipping\Domain\Measure;
use OrillaCart\Shipping\Domain\Parcel;

/**
 * Which rates can ship a parcel, priced for it: checkout's view of shipping. Taxes are checkout's to add.
 */
final readonly class ShippingQuotes
{
    public function __construct(private CarrierRepository $carriers) {}

    /**
     * @return list<ShippingOptionView> active carriers in their order, each carrier's rates in theirs
     */
    public function options(ShippingQuery $query): array
    {
        $destination = $query->country === null ? null : new Destination($query->country, $query->state);
        $measure = static fn(?string $value): Measure => $value === null || $value === '' ? Measure::zero() : Measure::of($value);
        $parcel = Parcel::of(array_map(static fn(ParcelItem $item): array => [
            'quantity' => $item->quantity,
            'weight' => $measure($item->weight),
            'length' => $measure($item->length),
            'width' => $measure($item->width),
            'height' => $measure($item->height),
        ], $query->items), Money::of($query->subtotal));

        $options = [];
        foreach ($this->carriers->all() as $carrier) {
            if (!$carrier->isActive()) {
                continue;
            }
            foreach ($carrier->rates() as $rate) {
                if ($rate->accepts($destination, $parcel)) {
                    $options[] = new ShippingOptionView(
                        $rate->id(),
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
}
