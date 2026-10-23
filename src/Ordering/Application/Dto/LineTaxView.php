<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * One rate on one line of an order: who it is owed to, what it was charged on before tax, how much, and what
 * refunds gave back of it. Amounts and the percentage are decimal strings.
 */
final readonly class LineTaxView
{
    public function __construct(
        public string $name,
        public string $percent,
        public string $country,
        public ?string $region,
        /** "country", "region" or "local". */
        public string $level,
        /** The code it is filed under. */
        public string $code,
        /** The jurisdiction's name. */
        public string $jurisdiction,
        public string $taxable,
        public string $amount,
        public bool $compound = false,
        public string $refunded = '0',
        /** Not charged: the customer's exemption covered the sale. */
        public bool $exempt = false,
    ) {}
}
