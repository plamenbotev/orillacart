<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain\Profile;

/**
 * How a country taxes sales, as far as setting up a shop goes.
 */
enum TaxSystem: string
{
    /** Value added tax, with standard and reduced rates: the EU, the UK, Switzerland, Norway. */
    case Vat = 'vat';
    /** Goods and services tax: a rate for the whole country (Australia, New Zealand) or by province (Canada). */
    case Gst = 'gst';
    /** A tax by state, with local taxes on top: the United States. */
    case SalesTax = 'sales_tax';
    /** A country the shop knows no rates for: the owner enters one, or none. */
    case Unknown = 'unknown';
}
