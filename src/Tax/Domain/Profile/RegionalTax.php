<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain\Profile;

use OrillaCart\Tax\Domain\Percentage;

/**
 * A tax of one state or province: Ontario's HST, British Columbia's GST and its PST each, a US state's own rate.
 * The taxes of a state add up.
 */
final readonly class RegionalTax
{
    public function __construct(
        public string $state,
        public string $name,
        public Percentage $percent,
    ) {}
}
