<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Domain\CustomerNote;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: note something about a customer, for the shop.
 */
final readonly class AddCustomerNote
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: CustomerNote::MAX_LENGTH)]
        public string $text,
    ) {}
}
