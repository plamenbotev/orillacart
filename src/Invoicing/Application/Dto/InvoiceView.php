<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

/**
 * An invoice or credit note of an order, with the address of its printable page.
 */
final readonly class InvoiceView
{
    public function __construct(
        public string $number,
        /** invoice or credit_note */
        public string $kind,
        public string $amount,
        public string $currency,
        public string $issuedAt,
        public string $url,
    ) {}
}
