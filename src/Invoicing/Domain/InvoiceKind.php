<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

enum InvoiceKind: string
{
    /** For an order once it is paid. */
    case Invoice = 'invoice';
    /** For money given back: it corrects an invoice. */
    case CreditNote = 'credit_note';
}
