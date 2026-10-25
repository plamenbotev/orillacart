<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A place customers collect their orders.
 */
final readonly class PickupAnswers
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 300)]
        public string $address,
        #[Assert\Length(max: 100)]
        public string $hours = '',
        #[Assert\Regex('/^\d{1,11}(\.\d{1,4})?$/')]
        public string $fee = '0',
    ) {}
}
