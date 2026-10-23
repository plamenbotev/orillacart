<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The shop's tax service could not work out the tax, and the shop chose to take no order without it.
 */
final class TaxServiceUnavailable extends RuleViolation
{
    public function __construct()
    {
        parent::__construct('The tax could not be worked out just now. Try again in a moment.', 'orillacart_tax_service_unavailable');
    }
}
