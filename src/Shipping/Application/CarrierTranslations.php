<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\PickupLocation;
use OrillaCart\Translations\Application\TranslatableType;

/** A carrier's name and its pickup places' names, addresses and hours, in the site's other languages. */
final readonly class CarrierTranslations implements TranslatableType
{
    public const string TYPE = 'carrier';

    /** A pickup place's translated fields, as "pickup.{key}.{field}". */
    public const array PICKUP_FIELDS = ['name', 'address', 'hours'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return [
            'name' => Carrier::MAX_NAME_LENGTH,
            'pickup.{key}.name' => PickupLocation::MAX_NAME_LENGTH,
            'pickup.{key}.address' => PickupLocation::MAX_TEXT_LENGTH,
            'pickup.{key}.hours' => PickupLocation::MAX_TEXT_LENGTH,
        ];
    }

    public static function pickupField(string $locationId, string $field): string
    {
        return "pickup.{$locationId}.{$field}";
    }
}
