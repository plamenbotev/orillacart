<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * The regular price and an optional sale, as products and variations store them.
 */
#[ORM\Embeddable]
final class Pricing
{
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $price;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $salePrice;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $saleFrom;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $saleUntil;

    public function __construct(Money $price, ?Sale $sale = null)
    {
        if ($price->isNegative()) {
            throw new CatalogRuleViolation('A price cannot be negative.', 'orillacart_invalid_price');
        }
        $this->price = $price->toFixed();
        $this->salePrice = $sale?->price->toFixed();
        $this->saleFrom = $sale?->from;
        $this->saleUntil = $sale?->until;
    }

    public function price(): Money
    {
        return Money::of($this->price);
    }

    public function sale(): ?Sale
    {
        return $this->salePrice === null ? null : new Sale(Money::of($this->salePrice), $this->saleFrom, $this->saleUntil);
    }

    /** The sale price while the sale runs, otherwise the regular price. */
    public function priceAt(\DateTimeImmutable $now): Money
    {
        $sale = $this->sale();

        return $sale !== null && $sale->isActiveAt($now) ? $sale->price : $this->price();
    }
}
