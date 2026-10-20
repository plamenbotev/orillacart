<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the shop noted about a customer, for itself: the customer never sees it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_notes')]
#[ORM\Index(name: 'customer_notes_of', columns: ['customer_id'])]
class CustomerNote
{
    public const int MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text')]
    private string $text;

    /**
     * @param int|null $userId who of the shop wrote it: a WordPress user id
     *
     * @throws CustomerRuleViolation for an empty note, or one too long
     */
    public function __construct(
        #[ORM\Column]
        private int $customerId,
        string $text,
        #[ORM\Column(nullable: true)]
        private ?int $userId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            throw new CustomerRuleViolation(\sprintf('A note has 1 to %d characters.', self::MAX_LENGTH), 'orillacart_invalid_note');
        }
        $this->text = $text;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The note has no id until it is saved.');
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
