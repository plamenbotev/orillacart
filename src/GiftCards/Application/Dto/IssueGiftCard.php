<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\GiftCards\Domain\GiftCard;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop makes a gift card, and sends it to whom it is for when an email is given.
 */
final readonly class IssueGiftCard
{
    public function __construct(
        #[Assert\Regex('/^\d{1,11}(\.\d{1,4})?$/', message: 'Use an amount with up to four decimals, such as 50.')]
        public string $amount,
        #[Assert\DateTime(format: \DATE_ATOM)]
        public ?string $expiresAt = null,
        #[Assert\Length(max: GiftCard::MAX_NAME_LENGTH)]
        public string $recipientName = '',
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public string $recipientEmail = '',
        #[Assert\Length(max: GiftCard::MAX_MESSAGE_LENGTH)]
        public string $message = '',
    ) {}
}
