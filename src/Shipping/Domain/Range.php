<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * From a lowest to a highest value, both included; without a highest value the range has no end.
 * The default range, from zero with no end, holds every parcel.
 */
#[ORM\Embeddable]
final class Range
{
    #[ORM\Column(name: 'min', type: 'decimal', precision: 18, scale: Measure::DECIMALS)]
    private string $min;

    #[ORM\Column(name: 'max', type: 'decimal', precision: 18, scale: Measure::DECIMALS, nullable: true)]
    private ?string $max;

    public function __construct(?Measure $min = null, ?Measure $max = null)
    {
        $min ??= Measure::zero();
        if ($max !== null && $max->compare($min) < 0) {
            throw new ShippingRuleViolation(\sprintf('A range cannot end (%s) below where it starts (%s).', $max, $min), 'orillacart_invalid_range');
        }
        $this->min = $min->toFixed();
        $this->max = $max?->toFixed();
    }

    public static function any(): self
    {
        return new self();
    }

    public function min(): Measure
    {
        return Measure::of($this->min);
    }

    public function max(): ?Measure
    {
        return $this->max === null ? null : Measure::of($this->max);
    }

    public function isAny(): bool
    {
        return $this->max === null && $this->min()->compare(Measure::zero()) === 0;
    }

    public function contains(Measure $value): bool
    {
        $max = $this->max();

        return $value->compare($this->min()) >= 0 && ($max === null || $value->compare($max) <= 0);
    }
}
