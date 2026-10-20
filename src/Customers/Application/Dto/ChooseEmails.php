<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

/**
 * Command: the emails a customer wants. Newsletter null leaves it as it is, for a shop without one.
 */
final readonly class ChooseEmails
{
    public function __construct(
        public bool $cartReminders = true,
        public ?bool $newsletter = null,
    ) {}
}
