<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * Which products a form is for. A checkout form is asked when the cart holds one of them.
 */
enum FormScope: string
{
    case All = 'all';
    /** Products in the chosen categories, their subcategories included. */
    case Categories = 'categories';
    case Products = 'products';
}
