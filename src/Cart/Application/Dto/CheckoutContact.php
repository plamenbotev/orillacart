<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the customer is at checkout with their cart, with this email so far, asking or not to be reminded of
 * the cart if they leave it.
 */
final readonly class CheckoutContact
{
    public function __construct(
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public ?string $email = null,
        public bool $remind = false,
    ) {}
}
