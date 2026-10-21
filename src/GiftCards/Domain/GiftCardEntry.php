<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One change of a gift card's balance: spent on an order (negative), given back, or added or taken off by the shop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gift_card_entries')]
#[ORM\Index(name: 'gift_card_entries_of', columns: ['card_id', 'id'])]
#[ORM\Index(name: 'gift_card_entries_of_order', columns: ['order_id'])]
class GiftCardEntry
{
    public const int MAX_NOTE_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    #[ORM\Column(length: self::MAX_NOTE_LENGTH)]
    private string $note;

    public function __construct(
        #[ORM\Column]
        private int $cardId,
        Money $amount,
        string $note,
        #[ORM\Column]
        private \DateTimeImmutable $at,
        /** The order it was spent on or given back from. */
        #[ORM\Column(nullable: true)]
        private ?int $orderId = null,
        /** The shop manager who made it; null for the shop itself. */
        #[ORM\Column(nullable: true)]
        private ?int $userId = null,
    ) {
        $this->amount = $amount->toFixed();
        $this->note = mb_substr(trim($note), 0, self::MAX_NOTE_LENGTH);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The entry has no id until it is saved.');
    }

    public function cardId(): int
    {
        return $this->cardId;
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function note(): string
    {
        return $this->note;
    }

    public function at(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}
