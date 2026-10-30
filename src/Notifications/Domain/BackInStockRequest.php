<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A visitor's wish to hear when a sold-out product or variation is back: their email, once per item, in the
 * language they asked in. Sent once and forgotten; forgotten too after half a year.
 */
#[ORM\Entity]
#[ORM\Table(name: 'back_in_stock')]
#[ORM\UniqueConstraint(name: 'back_in_stock_item_email', columns: ['item', 'email'])]
#[ORM\Index(name: 'back_in_stock_email', columns: ['email'])]
#[ORM\Index(name: 'back_in_stock_at', columns: ['asked_at'])]
class BackInStockRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** How often the mail system refused its email. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $refused = 0;

    public function __construct(
        /** As stock names it: "product:12", "variation:40". */
        #[ORM\Column(length: 40)]
        private string $item,
        #[ORM\Column]
        private int $productId,
        #[ORM\Column(length: 254)]
        private string $email,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $locale,
        #[ORM\Column(name: 'asked_at')]
        private \DateTimeImmutable $at,
    ) {}

    public function item(): string
    {
        return $this->item;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function at(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    /**
     * The mail system refused its email once more.
     *
     * @return int how often in all
     */
    public function refused(): int
    {
        return ++$this->refused;
    }
}
