<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

/**
 * A carrier's words in the site's other languages, for the views of the Shipping screen.
 */
final readonly class CarrierTexts
{
    /**
     * @param array<string, string>                       $name   by locale
     * @param array<int, array<string, string>>           $rates  the name by locale, by rate id
     * @param array<string, array<string, array<string, string>>> $pickup by place id, field and locale
     */
    public function __construct(
        public array $name = [],
        public array $rates = [],
        public array $pickup = [],
    ) {}
}
