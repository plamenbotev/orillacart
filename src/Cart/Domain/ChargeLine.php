<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A price of its own an add-on's cart line type keeps in the cart, with no product: a label, so much each and how
 * many (a deposit of 0.25 for each of six bottles). So much each is in the shop's currency, as its prices are
 * (with tax or without); the type keeps how many, and whether the shopper may remove it (Cart::keepAddOn()).
 */
#[ORM\Entity]
class ChargeLine extends CartLine
{
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $chargeAmount;

    /** @var list<int>|null the products it is charged for; none for the whole order */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $chargeFor = null;

    /**
     * @param list<int> $forProductIds the products it is charged for; none for the whole order
     */
    public function __construct(
        Cart $cart,
        int $quantity,
        int $position,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $addOnType,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $chargeLabel,
        Money $amount,
        #[ORM\Column(nullable: true)]
        private ?bool $chargeTaxable,
        #[ORM\Column(nullable: true)]
        private ?int $chargeTaxGroupId,
        #[ORM\Column(nullable: true)]
        private ?bool $removable,
        array $forProductIds = [],
    ) {
        parent::__construct($cart, null, [], $quantity, $position);
        $this->chargeAmount = $amount->toFixed();
        $this->chargeFor = self::normalized($forProductIds);
    }

    /** @return list<int> the products it is charged for; none for the whole order */
    public function forProductIds(): array
    {
        return $this->chargeFor ?? [];
    }

    public function type(): string
    {
        return 'charge';
    }

    public function quantityChangeable(): bool
    {
        return false;
    }

    public function removable(): bool
    {
        return $this->removable ?? false;
    }

    public function addOnType(): string
    {
        return $this->addOnType ?? '';
    }

    public function label(): string
    {
        return $this->chargeLabel ?? '';
    }

    /** So much each, in the shop's currency as its prices are. */
    public function amount(): Money
    {
        return Money::of($this->chargeAmount ?? '0');
    }

    public function taxable(): bool
    {
        return $this->chargeTaxable ?? true;
    }

    public function taxGroupId(): ?int
    {
        return $this->chargeTaxGroupId;
    }

    /** The shopper's items add to lines of their own: never to a charge. */
    public function isFor(int $productId, array $optionIds, array $bundleChoices = [], array $answers = []): bool
    {
        return false;
    }

    /** @internal for Cart */
    /**
     * @param list<int> $forProductIds
     */
    public function keepAs(int $quantity, Money $amount, bool $taxable, ?int $taxGroupId, bool $removable, array $forProductIds = []): void
    {
        $this->chargeFor = self::normalized($forProductIds);
        $this->setQuantity($quantity);
        $this->chargeAmount = $amount->toFixed();
        $this->chargeTaxable = $taxable;
        $this->chargeTaxGroupId = $taxGroupId;
        $this->removable = $removable;
    }
}
