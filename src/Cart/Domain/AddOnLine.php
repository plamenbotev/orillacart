<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A catalog product an add-on's cart line type keeps in the cart for what it holds (Cart::keepAddOn()): its
 * quantity is the type's, and the shopper may remove it when the type lets them.
 */
#[ORM\Entity]
class AddOnLine extends CartLine
{
    /**
     * @param list<int> $optionIds
     */
    public function __construct(
        Cart $cart,
        int $productId,
        array $optionIds,
        int $quantity,
        int $position,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $addOnType,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $addOnLabel,
        #[ORM\Column(nullable: true)]
        private ?bool $removable,
    ) {
        parent::__construct($cart, $productId, $optionIds, $quantity, $position);
    }

    public function type(): string
    {
        return 'add_on';
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
        return $this->addOnLabel ?? '';
    }

    /** The shopper's items add to lines of their own: never to one an add-on keeps. */
    public function isFor(int $productId, array $optionIds, array $bundleChoices = [], array $answers = []): bool
    {
        return false;
    }

    /**
     * @internal for Cart
     *
     * @param list<int> $optionIds
     */
    public function isOf(int $productId, array $optionIds): bool
    {
        return $this->productId() === $productId && $this->optionIds() === self::normalized($optionIds);
    }

    /** @internal for Cart */
    public function keepAs(int $quantity, string $label, bool $removable): void
    {
        $this->setQuantity($quantity);
        $this->addOnLabel = mb_substr($label, 0, 100);
        $this->removable = $removable;
    }
}
