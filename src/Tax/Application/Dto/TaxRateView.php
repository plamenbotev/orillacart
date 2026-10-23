<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxRate;

final readonly class TaxRateView
{
    public function __construct(
        public int $id,
        public string $country,
        public ?string $state,
        public string $percent,
        public string $name,
        public int $priority,
        /** @var list<string> */
        public array $postcodes,
        public bool $compound,
        /** The code it is filed under, as the shop entered it; null when built from the place. */
        public ?string $code = null,
        public ?JurisdictionView $jurisdiction = null,
        /** @var list<string> */
        public array $cities = [],
        public bool $shipping = true,
    ) {}

    public static function fromRate(TaxRate $rate): self
    {
        $details = $rate->details();

        return new self($rate->id(), $details->country, $details->state, (string) $details->percent, $details->name, $details->priority, array_map(strval(...), $details->postcodes), $details->compound, $details->code, JurisdictionView::fromJurisdiction($rate->jurisdiction()), $details->cities, $details->shipping);
    }
}
