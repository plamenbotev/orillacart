<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

final readonly class CustomerSettings
{
    public const int MIN_PASSWORD_LENGTH = 8;

    public function __construct(public AccountMode $accounts = AccountMode::Optional) {}
}
