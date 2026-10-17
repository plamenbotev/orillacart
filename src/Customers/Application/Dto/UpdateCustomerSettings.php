<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Domain\AccountMode;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateCustomerSettings
{
    public function __construct(
        #[Assert\Choice(callback: [AccountMode::class, 'values'])]
        public string $accounts,
    ) {}
}
