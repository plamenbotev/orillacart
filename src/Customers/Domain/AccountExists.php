<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

final class AccountExists extends RuleViolation
{
    public function __construct()
    {
        parent::__construct('There is already an account with this email address. Log in to order with it.', 'orillacart_account_exists');
    }
}
