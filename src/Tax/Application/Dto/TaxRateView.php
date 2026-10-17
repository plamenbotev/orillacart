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
    ) {}

    public static function fromRate(TaxRate $rate): self
    {
        $details = $rate->details();

        return new self($rate->id(), $details->country, $details->state, (string) $details->percent, $details->name, $details->priority);
    }
}
