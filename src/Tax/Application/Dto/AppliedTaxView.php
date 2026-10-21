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
    ) {}

    public static function fromTax(AppliedTax $tax): self
    {
        return new self($tax->rateId, $tax->name, (string) $tax->percent, $tax->compound);
    }
}
