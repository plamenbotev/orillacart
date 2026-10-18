<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Domain\CustomerGroup;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveCustomerGroup
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'Name the group.')]
        #[Assert\Length(max: CustomerGroup::MAX_NAME_LENGTH)]
        public string $name,
    ) {}
}
