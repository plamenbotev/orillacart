<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class InvoiceNotFound extends NotFound
{
    public function __construct()
    {
        parent::__construct('There is no such invoice.', 'orillacart_invoice_not_found');
    }
}
