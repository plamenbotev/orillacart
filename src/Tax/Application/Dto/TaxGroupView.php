<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxGroup;

final readonly class TaxGroupView
{
    /**
     * @param list<TaxRateView> $rates by priority
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $rates,
    ) {}

    public static function fromGroup(TaxGroup $group): self
    {
        return new self($group->id(), $group->name(), array_map(TaxRateView::fromRate(...), $group->rates()));
    }
}
