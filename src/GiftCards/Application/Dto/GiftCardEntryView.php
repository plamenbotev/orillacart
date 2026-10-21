<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use OrillaCart\GiftCards\Domain\GiftCardEntry;

final readonly class GiftCardEntryView
{
    public function __construct(
        public int $id,
        /** Negative when spent or taken off. */
        public string $amount,
        public string $note,
        public string $at,
        public ?int $orderId,
        /** Who made it; empty for the shop itself. */
        public string $user,
    ) {}

    public static function of(GiftCardEntry $entry, string $user): self
    {
        return new self($entry->id(), (string) $entry->amount(), $entry->note(), $entry->at()->format(\DATE_ATOM), $entry->orderId(), $user);
    }
}
