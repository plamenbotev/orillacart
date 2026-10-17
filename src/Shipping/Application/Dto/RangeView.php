<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\Range;

final readonly class RangeView
{
    public function __construct(
        public string $min,
        public ?string $max,
    ) {}

    /** Null for a range that takes any parcel, as the admin form leaves it empty. */
    public static function fromRange(Range $range): ?self
    {
        return $range->isAny() ? null : new self((string) $range->min(), $range->max() === null ? null : (string) $range->max());
    }
}
