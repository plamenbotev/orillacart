<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Application\Dto\CarrierTexts;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\PickupLocation;
use OrillaCart\Shipping\Domain\ShippingRate;
use OrillaCart\Translations\Application\ContentTranslations;
use OrillaCart\Translations\Domain\TranslationRuleViolation;

/**
 * A carrier's words in the site's other languages (design 51): its name, its rates' and its pickup places', saved
 * with the carrier, as the attributes' are with the product. Checked before the save, written once the save gave
 * new rates their ids, and gone with the rates and places the save removed.
 */
final readonly class ShippingTexts
{
    public function __construct(private ContentTranslations $translations) {}

    /**
     * @param list<Carrier> $carriers
     *
     * @return array<int, CarrierTexts> by carrier id
     */
    public function of(array $carriers): array
    {
        $ids = array_map(static fn(Carrier $carrier): int => $carrier->id(), $carriers);
        $own = $this->translations->of(CarrierTranslations::TYPE, $ids);
        $rates = $this->translations->of(RateTranslations::TYPE, array_merge([], ...array_map(self::rateIds(...), $carriers)));
        $texts = [];
        foreach ($carriers as $carrier) {
            $fields = $own[$carrier->id()] ?? [];
            $pickup = [];
            foreach ($carrier->pickupLocations() as $location) {
                foreach (CarrierTranslations::PICKUP_FIELDS as $field) {
                    $values = $fields[CarrierTranslations::pickupField($location->id, $field)] ?? [];
                    if ($values !== []) {
                        $pickup[$location->id][$field] = $values;
                    }
                }
            }
            $texts[$carrier->id()] = new CarrierTexts(
                $fields['name'] ?? [],
                array_map(static fn(array $rate): array => $rate['name'] ?? [], array_intersect_key($rates, array_flip(self::rateIds($carrier)))),
                $pickup,
            );
        }

        return $texts;
    }

    /**
     * Checked before the save: a refused translation refuses it, before anything is written.
     *
     * @return array{array<string, array<string, string>>, list<?array<string, array<string, string>>>} the carrier's
     *                                                                                                   fields (its places' too), and each rate's, null for none sent
     *
     * @throws TranslationRuleViolation
     */
    public function check(SaveCarrier $command): array
    {
        $fields = $command->translations === null ? [] : ['name' => $command->translations];
        foreach ($command->pickupLocations as $location) {
            foreach ($location->translations === null ? [] : CarrierTranslations::PICKUP_FIELDS as $field) {
                $fields[CarrierTranslations::pickupField($location->id, $field)] = $location->translations[$field] ?? [];
            }
        }

        return [
            $this->translations->check(CarrierTranslations::TYPE, $fields),
            array_map(fn(SaveShippingRate $rate): ?array => $rate->translations === null ? null : $this->translations->check(RateTranslations::TYPE, ['name' => $rate->translations]), $command->rates),
        ];
    }

    /**
     * Writes what check() returned once the carrier is saved, and forgets the rates' and places' it no longer has.
     *
     * @param array{array<string, array<string, string>>, list<?array<string, array<string, string>>>} $checked
     * @param list<int>                                                                                $ratesBefore
     * @param list<string>                                                                             $placesBefore
     */
    public function write(array $checked, Carrier $carrier, array $ratesBefore, array $placesBefore): void
    {
        [$fields, $rates] = $checked;
        foreach (array_diff($placesBefore, self::placeIds($carrier)) as $gone) {
            foreach (CarrierTranslations::PICKUP_FIELDS as $field) {
                $fields[CarrierTranslations::pickupField($gone, $field)] = [];
            }
        }
        $this->translations->write(CarrierTranslations::TYPE, $carrier->id(), $fields);
        foreach ($carrier->rates() as $i => $rate) {
            $translations = $rates[$i] ?? null;
            if ($translations !== null) {
                $this->translations->write(RateTranslations::TYPE, $rate->id(), $translations);
            }
        }
        $this->translations->forget(RateTranslations::TYPE, array_values(array_diff($ratesBefore, self::rateIds($carrier))));
    }

    /** The carrier is gone, and its rates. */
    public function forget(Carrier $carrier): void
    {
        $this->translations->forget(CarrierTranslations::TYPE, [$carrier->id()]);
        $this->translations->forget(RateTranslations::TYPE, self::rateIds($carrier));
    }

    /**
     * The carriers' words as the shopper reads them: in the language shown, else their own.
     *
     * @param list<Carrier> $carriers
     */
    public function shown(array $carriers): ShippingWords
    {
        $carrierIds = array_map(static fn(Carrier $carrier): int => $carrier->id(), $carriers);
        $rateIds = array_merge([], ...array_map(self::rateIds(...), $carriers));

        return new ShippingWords($this->translations->shown(CarrierTranslations::TYPE, $carrierIds), $this->translations->translated(RateTranslations::TYPE, $rateIds));
    }

    /**
     * @return list<int>
     */
    public static function rateIds(Carrier $carrier): array
    {
        return array_values(array_map(static fn(ShippingRate $rate): int => $rate->id(), array_filter($carrier->rates(), static fn(ShippingRate $rate): bool => $rate->isSaved())));
    }

    /**
     * @return list<string>
     */
    public static function placeIds(Carrier $carrier): array
    {
        return array_map(static fn(PickupLocation $location): string => $location->id, $carrier->pickupLocations());
    }
}
