<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * Where a form is asked: its answers belong to the item bought, or to the order.
 */
enum FormPlacement: string
{
    case Product = 'product';
    case Checkout = 'checkout';
}
