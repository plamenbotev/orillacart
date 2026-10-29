<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Domain\RateDetails;
use OrillaCart\Translations\Application\TranslatableType;

/** The names of carriers' own rates in the site's other languages. */
final readonly class RateTranslations implements TranslatableType
{
    public const string TYPE = 'shipping_rate';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return ['name' => RateDetails::MAX_NAME_LENGTH];
    }
}
