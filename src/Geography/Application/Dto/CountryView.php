<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Application\Dto;

use OrillaCart\Geography\Domain\Country;

final readonly class CountryView
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $inEu,
        public bool $hasStates,
    ) {}

    public static function fromCountry(Country $country): self
    {
        return new self($country->code, $country->name, $country->inEu, $country->hasStates());
    }
}
