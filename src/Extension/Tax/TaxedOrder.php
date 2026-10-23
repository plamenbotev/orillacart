<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * A placed order as a tax service records it: its lines with the taxes they charged.
 */
final readonly class TaxedOrder
{
    /**
     * @param list<TaxRequestLine>         $lines
     * @param array<string, list<TaxLine>> $taxes     by line id, as charged
     * @param string|null                  $reference the service's reference, once it recorded the sale
     */
    public function __construct(
        public int $number,
        public string $currency,
        public string $date,
        public TaxAddress $shipFrom,
        public TaxAddress $shipTo,
        public array $lines,
        public array $taxes,
        public ?int $customerId = null,
        public ?string $exemption = null,
        public ?string $reference = null,
    ) {}
}
