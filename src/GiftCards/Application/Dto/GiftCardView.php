<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\Shared\Domain\Text;

final readonly class GiftCardView
{
    public function __construct(
        public int $id,
        /** gift_card or store_credit */
        public string $kind,
        /** "Gift card ····7KQ2", or "Store credit". */
        public Text $label,
        /** The code's last four characters; empty for store credit. */
        public string $codeEnd,
        public string $initial,
        public string $balance,
        public string $currency,
        public ?string $expiresAt,
        public bool $active,
        public string $recipientName,
        public string $recipientEmail,
        public string $message,
        /** The order it was bought with; null for one the shop gave. */
        public ?int $orderId,
        /** Whose store credit. */
        public ?int $customerId,
        public string $createdAt,
    ) {}

    public static function of(GiftCard $card): self
    {
        return new self(
            $card->id(),
            $card->kind()->value,
            $card->label(),
            $card->codeEnd(),
            (string) $card->initial(),
            (string) $card->balance(),
            $card->currency(),
            $card->expiresAt()?->format(\DATE_ATOM),
            $card->isActive(),
            $card->recipientName(),
            $card->recipientEmail(),
            $card->message(),
            $card->orderId(),
            $card->customerId(),
            $card->createdAt()->format(\DATE_ATOM),
        );
    }
}
