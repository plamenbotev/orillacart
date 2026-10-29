<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** A carrier's words for the translation service: its name, its pickup places' and its rates'. */
final readonly class CarrierPackages implements TranslationPackageSource
{
    public const string KIND = 'carrier';

    private const array PLACE_TITLES = ['name' => 'Pickup place %s: name', 'address' => 'Pickup place %s: address', 'hours' => 'Pickup place %s: opening hours'];

    public function __construct(private CarrierRepository $carriers) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart carrier';
    }

    public function package(int $id): ?TranslationPackage
    {
        $carrier = $this->carriers->find($id);
        if ($carrier === null) {
            return null;
        }
        $strings = [new PackageString(CarrierTranslations::TYPE, $id, 'name', $carrier->name(), Text::of('Name'))];
        foreach ($carrier->pickupLocations() as $place) {
            foreach (CarrierTranslations::PICKUP_FIELDS as $field) {
                $strings[] = new PackageString(CarrierTranslations::TYPE, $id, CarrierTranslations::pickupField($place->id, $field), $place->{$field}, Text::of(self::PLACE_TITLES[$field], $place->name), $field !== 'name');
            }
        }
        foreach ($carrier->rates() as $rate) {
            $strings[] = new PackageString(RateTranslations::TYPE, $rate->id(), 'name', $rate->name(), Text::of('Rate: %s', $rate->name()));
        }

        return TranslationPackage::of(self::KIND, $id, $carrier->name(), $strings);
    }

    public function ids(): iterable
    {
        return array_map(static fn(Carrier $carrier): int => $carrier->id(), $this->carriers->all());
    }
}
