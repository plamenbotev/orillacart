<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A product's or variation's price for everyone, from the moment it was first seen: kept so a sale can say the
 * lowest price of the 30 days before it, as the EU's Omnibus directive asks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_history')]
#[ORM\Index(name: 'price_item', columns: ['item', 'recorded_at'])]
class PricePoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $price;

    public function __construct(
        /** "product:12" or "variation:5". */
        #[ORM\Column(length: 40)]
        private string $item,
        Money $price,
        #[ORM\Column]
        private \DateTimeImmutable $recordedAt,
    ) {
        $this->price = $price->toFixed();
    }

    public function item(): string
    {
        return $this->item;
    }

    public function price(): Money
    {
        return Money::of($this->price);
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
