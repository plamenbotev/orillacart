<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A cart left at checkout: what it held when the shop counted it abandoned, whether its customer was reminded,
 * and the order placed from it after, if any. Kept for the report after the cart is gone; the email only while
 * the cart is kept.
 */
#[ORM\Entity]
#[ORM\Table(name: 'abandoned_carts')]
#[ORM\Index(name: 'abandoned_at', columns: ['abandoned_at'])]
#[ORM\Index(name: 'abandoned_email', columns: ['email'])]
class AbandonedCart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $orderTotal = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recoveredAt = null;

    public function __construct(
        #[ORM\Column]
        private int $cartId,
        #[ORM\Column(nullable: true)]
        private ?int $customerId,
        /** Only for a customer who asked to be reminded. */
        #[ORM\Column(length: 254, nullable: true)]
        private ?string $email,
        #[ORM\Column]
        private int $items,
        #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
        private string $subtotal,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column]
        private \DateTimeImmutable $abandonedAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The abandoned cart has no id until it is saved.');
    }

    public function cartId(): int
    {
        return $this->cartId;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function items(): int
    {
        return $this->items;
    }

    public function subtotal(): string
    {
        return $this->subtotal;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function abandonedAt(): \DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function remindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function orderTotal(): ?string
    {
        return $this->orderTotal;
    }

    public function recoveredAt(): ?\DateTimeImmutable
    {
        return $this->recoveredAt;
    }

    public function isRecovered(): bool
    {
        return $this->orderId !== null;
    }

    public function reminded(\DateTimeImmutable $now): void
    {
        $this->remindedAt = $now;
    }

    /** An order was placed from the cart after it was left. */
    public function recovered(int $orderId, string $total, \DateTimeImmutable $now): void
    {
        $this->orderId = $orderId;
        $this->orderTotal = $total;
        $this->recoveredAt = $now;
    }

    public function forgetEmail(): void
    {
        $this->email = null;
    }
}
