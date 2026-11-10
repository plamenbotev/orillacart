<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Ordering\Application\ReceiptHandles;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: email a new link to the receipt an expired one was for.
 */
final readonly class SendReceiptLink
{
    public function __construct(
        #[Assert\Regex(pattern: '/^(' . ReceiptHandles::PATTERN . ')$/')]
        public string $receipt,
    ) {}
}
