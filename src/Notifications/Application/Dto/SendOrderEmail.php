<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Notifications\Domain\EmailKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: send one of an order's emails again, such as when the customer lost it.
 */
final readonly class SendOrderEmail
{
    public function __construct(
        #[Assert\Choice(callback: [EmailKind::class, 'values'])]
        public string $kind,
    ) {}
}
