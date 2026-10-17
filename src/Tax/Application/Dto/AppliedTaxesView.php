<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\AppliedTaxes;

/**
 * The taxes on an item: their sum, and each one as the cart and invoices list it. Percentages are
 * decimal strings, such as "7.25".
 */
final readonly class AppliedTaxesView
{
    /**
     * @param list<AppliedTaxView> $taxes
     */
    public function __construct(
        public string $total,
        public array $taxes,
    ) {}

    public static function fromTaxes(AppliedTaxes $taxes): self
    {
        return new self((string) $taxes->total(), array_map(AppliedTaxView::fromTax(...), $taxes->taxes));
    }
}
