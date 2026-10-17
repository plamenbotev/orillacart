<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: note something on an order; a note for the customer is also emailed to them.
 */
final readonly class AddOrderNote
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: OrderNote::MAX_LENGTH)]
        public string $text,
        public bool $forCustomer = false,
    ) {}
}
