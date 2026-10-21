<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\GiftCards\Domain\GiftCardEntry;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop adds to a balance, or takes off it with a minus, and says why.
 */
final readonly class AdjustBalance
{
    public function __construct(
        #[Assert\Regex('/^-?\d{1,11}(\.\d{1,4})?$/', message: 'Use an amount such as 20, or -5 to take off.')]
        public string $amount,
        #[Assert\NotBlank(message: 'Say why, for the record.')]
        #[Assert\Length(max: GiftCardEntry::MAX_NOTE_LENGTH)]
        public string $note,
    ) {}
}
