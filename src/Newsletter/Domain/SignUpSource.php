<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

/** Where someone signed up for the newsletter. */
enum SignUpSource: string
{
    case Checkout = 'checkout';
    case Account = 'account';
}
