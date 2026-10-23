<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\AppliedTax;

final readonly class AppliedTaxView
{
    public function __construct(
        public ?int $rateId,
        public string $name,
        public string $percent,
        /** Charged on the price and the taxes listed before it. */
        public bool $compound = false,
        /** Who it is owed to: country, region (the state's code), level, the code it is filed under, and its name. */
        public ?JurisdictionView $jurisdiction = null,
    ) {}

    public static function fromTax(AppliedTax $tax): self
    {
        return new self($tax->rateId, $tax->name, (string) $tax->percent, $tax->compound, $tax->jurisdiction === null ? null : JurisdictionView::fromJurisdiction($tax->jurisdiction));
    }
}
