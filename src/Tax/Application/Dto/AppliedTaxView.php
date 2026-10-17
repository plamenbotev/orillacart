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
    ) {}

    public static function fromTax(AppliedTax $tax): self
    {
        return new self($tax->rateId, $tax->name, (string) $tax->percent);
    }
}
