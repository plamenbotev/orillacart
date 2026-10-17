<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Application\Dto;

/**
 * A known country, and a state only when it is one of that country's.
 */
final readonly class LocationView
{
    public function __construct(
        public string $country,
        public ?string $state,
        public bool $inEu,
    ) {}
}
