<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a customer means to buy. A cart does not hold stock: checkout takes it when the order is placed.
 * The browser keeps the cart's token in a cookie; the database keeps only its hash, so a copy of the
 * database does not give access to anyone's cart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts')]
#[ORM\UniqueConstraint(name: 'cart_token', columns: ['token_hash'])]
#[ORM\Index(name: 'cart_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'cart_updated', columns: ['updated_at'])]
class Cart
{
    public const int MAX_LINES = 100;
    public const int MAX_QUANTITY = 9999;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** @var Collection<int, CartLine> */
    #[ORM\OneToMany(targetEntity: CartLine::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var list<string> coupon codes, as entered; checkout decides which hold */
    #[ORM\Column(type: 'json')]
    private array $coupons = [];

    public function __construct(
        string $token,
        #[ORM\Column(nullable: true)]
        private ?int $customerId,
        \DateTimeImmutable $now,
    ) {
        $this->tokenHash = self::hash($token);
        $this->lines = new ArrayCollection();
        $this->updatedAt = $now;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The cart has no id until it is saved.');
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    /** A new token for the cart, for a customer who comes back on another device: the old cookie stops working. */
    public function rekey(string $token): void
    {
        $this->tokenHash = self::hash($token);
    }

    /** A customer who logged in keeps the cart they filled as a visitor. */
    public function belongTo(int $customerId): void
    {
        $this->customerId ??= $customerId;
    }

    /** @return list<CartLine> in the order they were added */
    public function lines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn(CartLine $a, CartLine $b): int => $a->position() <=> $b->position());

        return $lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    public function line(int $id): CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->isSaved() && $line->id() === $id) {
                return $line;
            }
        }

        throw new CartLineNotFound($id);
    }

    /**
     * Adds items; the same product with the same options adds to its line.
     *
     * @param list<int> $optionIds
     *
     * @return CartLine the line now holding them
     */
    public function add(int $productId, array $optionIds, int $quantity, \DateTimeImmutable $now): CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->isFor($productId, $optionIds)) {
                $line->setQuantity($line->quantity() + $quantity);
                $this->updatedAt = $now;

                return $line;
            }
        }
        if ($this->lines->count() >= self::MAX_LINES) {
            throw new CartRuleViolation(\sprintf('A cart holds up to %d different items.', self::MAX_LINES), 'orillacart_cart_full');
        }
        $position = 0;
        foreach ($this->lines as $line) {
            $position = max($position, $line->position() + 1);
        }
        $line = new CartLine($this, $productId, $optionIds, $quantity, $position);
        $this->lines->add($line);
        $this->updatedAt = $now;

        return $line;
    }

    /**
     * @throws CartLineNotFound
     */
    public function changeQuantity(int $lineId, int $quantity, \DateTimeImmutable $now): void
    {
        $this->line($lineId)->setQuantity($quantity);
        $this->updatedAt = $now;
    }

    /**
     * @throws CartLineNotFound
     */
    public function remove(int $lineId, \DateTimeImmutable $now): void
    {
        $this->lines->removeElement($this->line($lineId));
        $this->updatedAt = $now;
    }

    /** @return list<string> */
    public function coupons(): array
    {
        return $this->coupons;
    }

    /**
     * @param list<string> $codes
     */
    public function useCoupons(array $codes, \DateTimeImmutable $now): void
    {
        $this->coupons = \array_slice(array_values(array_unique($codes)), 0, 10);
        $this->updatedAt = $now;
    }

    public function clear(\DateTimeImmutable $now): void
    {
        $this->coupons = [];
        $this->lines->clear();
        $this->updatedAt = $now;
    }
}
