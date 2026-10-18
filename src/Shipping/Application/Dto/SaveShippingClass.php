<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use OrillaCart\Shipping\Domain\ShippingClass;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveShippingClass
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ShippingClass::MAX_NAME_LENGTH)]
        public string $name,
    ) {}
}
