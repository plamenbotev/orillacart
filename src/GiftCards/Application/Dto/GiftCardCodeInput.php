<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A gift card code as the customer typed it.
 */
final readonly class GiftCardCodeInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Enter the code.')]
        #[Assert\Length(max: 40)]
        public string $code,
    ) {}
}
