<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * The taxes charged at one location, in the order they are listed. They add up: 4 % and 0.375 % make 4.375 %;
 * a compound one is charged on the price and the taxes before it, so 5 % and a compound 9.975 % make 15.4738 %.
 */
final readonly class AppliedTaxes
{
    /**
     * @param list<AppliedTax> $taxes
     */
    public function __construct(public array $taxes = []) {}

    public static function none(): self
    {
        return new self();
    }

    public function total(): Percentage
    {
        return array_reduce($this->taxes, static fn(Percentage $sum, AppliedTax $tax): Percentage => $sum->add($tax->compound ? $tax->percent->on($sum) : $tax->percent), Percentage::zero());
    }

    public function isEmpty(): bool
    {
        return $this->taxes === [];
    }
}
