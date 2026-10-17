<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

/**
 * One rate of a carrier's list as an admin saves it: an existing rate by id, or a new one.
 */
final readonly class RateDraft
{
    public function __construct(
        public ?int $id,
        public RateDetails $details,
    ) {}
}
