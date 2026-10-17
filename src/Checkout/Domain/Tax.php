<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Domain;

/**
 * A tax rate charged on part of a bill. Rates with the same key are one line of the invoice.
 */
final readonly class Tax
{
    public function __construct(
        public string $key,
        public string $name,
        public string $percent,
    ) {}
}
