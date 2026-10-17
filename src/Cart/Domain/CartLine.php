<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product with the customer's choice of options, and how many. Prices are not kept: the cart shows the
 * catalog's current ones.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_lines')]
class CartLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var list<int> sorted */
    #[ORM\Column(type: 'json')]
    private array $optionIds;

    #[ORM\Column]
    private int $quantity;

    /**
     * @param list<int> $optionIds
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'cart_id', nullable: false, onDelete: 'CASCADE')]
        private Cart $cart,
        #[ORM\Column]
        private int $productId,
        array $optionIds,
        int $quantity,
        #[ORM\Column]
        private int $position,
    ) {
        $this->optionIds = self::normalized($optionIds);
        $this->setQuantity($quantity);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The cart line has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function cart(): Cart
    {
        return $this->cart;
    }

    public function productId(): int
    {
        return $this->productId;
    }

    /** @return list<int> */
    public function optionIds(): array
    {
        return $this->optionIds;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * @param list<int> $optionIds
     */
    public function isFor(int $productId, array $optionIds): bool
    {
        return $this->productId === $productId && $this->optionIds === self::normalized($optionIds);
    }

    /** @internal for Cart */
    public function setQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > Cart::MAX_QUANTITY) {
            throw new CartRuleViolation(\sprintf('A cart line holds 1 to %d items.', Cart::MAX_QUANTITY), 'orillacart_invalid_quantity');
        }
        $this->quantity = $quantity;
    }

    /**
     * @param list<int> $optionIds
     *
     * @return list<int>
     */
    private static function normalized(array $optionIds): array
    {
        $optionIds = array_values(array_unique($optionIds));
        sort($optionIds);

        return $optionIds;
    }
}
