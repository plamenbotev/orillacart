<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product with the customer's choice of options (and answers to its forms), and how many. Prices are not kept:
 * the cart shows the catalog's current ones.
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

    /** @var list<int> sorted: for a bundle, the variations chosen and the optional products added */
    #[ORM\Column(type: 'json')]
    private array $bundleChoices;

    /** @var array<string, string> answers to the product's forms, by field id, sorted: as FieldAnswers::values() keeps them */
    #[ORM\Column(type: 'json')]
    private array $answers;

    /**
     * @param list<int>             $optionIds
     * @param list<int>             $bundleChoices
     * @param array<string, string> $answers
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
        array $bundleChoices = [],
        array $answers = [],
    ) {
        $this->optionIds = self::normalized($optionIds);
        $this->bundleChoices = self::normalized($bundleChoices);
        ksort($answers, SORT_STRING);
        $this->answers = $answers;
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

    /** @return list<int> */
    public function bundleChoices(): array
    {
        return $this->bundleChoices;
    }

    /** @return array<string, string> */
    public function answers(): array
    {
        return $this->answers;
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
     * @param list<int>             $optionIds
     * @param list<int>             $bundleChoices
     * @param array<string, string> $answers
     */
    public function isFor(int $productId, array $optionIds, array $bundleChoices = [], array $answers = []): bool
    {
        ksort($answers, SORT_STRING);

        return $this->productId === $productId && $this->optionIds === self::normalized($optionIds) && $this->bundleChoices === self::normalized($bundleChoices) && $this->answers === $answers;
    }

    /** @internal for Cart */
    public function setQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > Cart::MAX_QUANTITY) {
            throw new CartRuleViolation('A cart line holds 1 to %d items.', 'orillacart_invalid_quantity', values: [Cart::MAX_QUANTITY]);
        }
        $this->quantity = $quantity;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function normalized(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
