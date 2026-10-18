<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the customer pays an unpaid order now, with the method chosen.
 */
final readonly class PayOrder
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        public string $payment,
    ) {}
}
