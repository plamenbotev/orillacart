<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: whether a gift card pays, and until when.
 */
final readonly class ChangeGiftCard
{
    public function __construct(
        public bool $active = true,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $expiresAt = null,
    ) {}
}
