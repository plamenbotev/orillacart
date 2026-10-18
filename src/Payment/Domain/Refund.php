<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * Money given back for an order: how, how much, for which items, and what the provider called it. A refund
 * through a provider is saved as pending before the provider is asked, so a refund it took while the shop
 * crashed or timed out is never lost, nor sent twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refunds')]
#[ORM\Index(name: 'refund_order', columns: ['order_id'])]
#[ORM\Index(name: 'refund_reference', columns: ['gateway', 'provider_reference'])]
class Refund
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    #[ORM\Column(length: 20, enumType: RefundStatus::class)]
    private RefundStatus $status;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $providerReference = null;

    /** The provider's answer when it refused, or could not be reached. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    /** The provider is still sending the money. */
    #[ORM\Column]
    private bool $providerPending = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /**
     * @param array<int, int> $quantities items it covers, by order line position
     */
    public function __construct(
        #[ORM\Column]
        private int $orderId,
        #[ORM\Column(nullable: true)]
        private ?int $paymentId,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $gateway,
        #[ORM\Column(length: 20, enumType: RefundMethod::class)]
        private RefundMethod $method,
        Money $amount,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(type: 'json')]
        private array $quantities,
        #[ORM\Column]
        private bool $shipping,
        #[ORM\Column]
        private bool $restock,
        #[ORM\Column(type: 'text')]
        private string $reason,
        #[ORM\Column(nullable: true)]
        private ?int $userId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new PaymentRuleViolation('A refund is for more than nothing.', 'orillacart_invalid_refund_amount');
        }
        $this->amount = $amount->toFixed();
        $this->status = $method === RefundMethod::Provider ? RefundStatus::Pending : RefundStatus::Done;
        if ($this->status === RefundStatus::Done) {
            $this->settledAt = $createdAt;
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The refund has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function paymentId(): ?int
    {
        return $this->paymentId;
    }

    public function gateway(): ?string
    {
        return $this->gateway;
    }

    public function method(): RefundMethod
    {
        return $this->method;
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** @return array<int, int> */
    public function quantities(): array
    {
        $quantities = [];
        foreach ($this->quantities as $position => $quantity) {
            $quantities[(int) $position] = (int) $quantity;
        }

        return $quantities;
    }

    public function coversShipping(): bool
    {
        return $this->shipping;
    }

    public function restocks(): bool
    {
        return $this->restock;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function status(): RefundStatus
    {
        return $this->status;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function isProviderPending(): bool
    {
        return $this->providerPending;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function settledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    /** The id the provider gets each time, so it refunds once however often it is asked. */
    public function idempotencyKey(): string
    {
        return 'orillacart-refund-' . $this->id();
    }

    /**
     * @return bool false when it was settled before
     */
    public function complete(string $providerReference, bool $providerPending, \DateTimeImmutable $at): bool
    {
        if ($this->status !== RefundStatus::Pending) {
            return false;
        }
        $this->status = RefundStatus::Done;
        $this->providerReference = mb_substr($providerReference, 0, 100);
        $this->providerPending = $providerPending;
        $this->error = null;
        $this->settledAt = $at;

        return true;
    }

    /** The provider could not be reached: it stays pending, to try again. */
    public function unanswered(string $error): void
    {
        if ($this->status === RefundStatus::Pending) {
            $this->error = $error;
        }
    }

    public function fail(string $error, \DateTimeImmutable $at): bool
    {
        if ($this->status !== RefundStatus::Pending) {
            return false;
        }
        $this->status = RefundStatus::Failed;
        $this->error = $error;
        $this->settledAt = $at;

        return true;
    }

    /** For a refund made at the provider: its id there. */
    public function refer(string $providerReference): void
    {
        $this->providerReference = mb_substr($providerReference, 0, 100);
    }
}
