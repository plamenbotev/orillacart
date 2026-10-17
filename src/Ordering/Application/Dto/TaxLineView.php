<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * One tax rate charged, over items and shipping: "VAT", "20", "16.67".
 */
final readonly class TaxLineView
{
    public function __construct(
        public string $name,
        public string $percent,
        public string $amount,
    ) {}
}
