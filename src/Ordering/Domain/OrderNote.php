<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A line of an order's history: what happened, or what the shop noted. Notes for the customer show on their
 * receipt and are emailed to them; the others stay in the shop.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_notes')]
class OrderNote
{
    public const int MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $text;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'notes')]
        #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
        private Order $order,
        string $text,
        #[ORM\Column(length: 20, enumType: NoteAuthor::class)]
        private NoteAuthor $author,
        #[ORM\Column]
        private bool $forCustomer,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        /** Who of the shop wrote it: a WordPress user id. */
        #[ORM\Column(nullable: true)]
        private ?int $userId = null,
    ) {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            throw new OrderRuleViolation(\sprintf('A note has 1 to %d characters.', self::MAX_LENGTH), 'orillacart_invalid_note');
        }
        $this->text = $text;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The note has no id until it is saved.');
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function author(): NoteAuthor
    {
        return $this->author;
    }

    public function isForCustomer(): bool
    {
        return $this->forCustomer;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}
