<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Tax;

/**
 * A service's tax for every line it was asked about. A line left out owes no tax.
 */
final readonly class TaxResult
{
    /**
     * @param array<string, list<TaxLine>> $lines the taxes by the request line's id
     */
    public function __construct(public array $lines) {}
}
