<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An item a discount gives with what the shopper bought (a free gift, the item buy-get gives free): the shop
 * keeps it in step with the cart (Cart::keepGiven()). Its quantity is the discount's; the shopper may remove it,
 * and the cart then remembers they did not want it.
 */
#[ORM\Entity]
class GivenLine extends CartLine
{
    /**
     * @param list<int> $optionIds
     * @param list<int> $bundleChoices
     */
    public function __construct(
        Cart $cart,
        int $productId,
        array $optionIds,
        int $quantity,
        int $position,
        array $bundleChoices,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $givenCode,
        /** The discount's description, as the shopper read it when it was given. */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $givenLabel,
    ) {
        parent::__construct($cart, $productId, $optionIds, $quantity, $position, $bundleChoices);
    }

    public function type(): string
    {
        return 'given';
    }

    public function quantityChangeable(): bool
    {
        return false;
    }

    public function givenBy(): string
    {
        return $this->givenCode ?? '';
    }

    public function label(): string
    {
        return $this->givenLabel ?? '';
    }

    /** @internal for Cart: in step with what the discount gives now. */
    public function giveAs(GivenItem $item): void
    {
        $this->setQuantity($item->quantity);
        $this->givenLabel = mb_substr($item->label, 0, 255);
    }

    /** The shopper's items add to lines of their own: never to one given. */
    public function isFor(int $productId, array $optionIds, array $bundleChoices = [], array $answers = []): bool
    {
        return false;
    }

    /** Whether it is the item the discount gives, whatever the quantity. */
    public function isAs(GivenItem $item): bool
    {
        return $this->givenBy() === $item->code && $this->productId() === $item->productId && $this->optionIds() === self::normalized($item->optionIds) && $this->bundleChoices() === self::normalized($item->bundleChoices);
    }
}
