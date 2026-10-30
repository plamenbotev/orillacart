<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\References;

/**
 * Money given back for an order: how, how much, for which items, and what the provider called it. A refund
 * through a provider is saved as pending before the provider is asked, so a refund it took while the shop
 * crashed or timed out is never lost, nor sent twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'refunds')]
#[ORM\Index(name: 'refund_order', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'refund_reference', columns: ['gateway', 'provider_reference'])]
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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /** The tax inside the amount, once the order recorded the refund. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $tax = '0';

    /** @var list<array{name: string, percent: string, amount: string, code?: string}> the tax by the order's rates */
    #[ORM\Column(type: 'json')]
    private array $taxes = [];

    /**
     * @param array<int, int> $quantities items it covers, by order line position
     */
    public function __construct(
        #[ORM\Column]
        #[References('orders')]
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

    public function tax(): Money
    {
        return Money::of($this->tax);
    }

    /** @return list<array{name: string, percent: string, amount: string, code?: string}> */
    public function taxes(): array
    {
        return $this->taxes;
    }

    /**
     * The order recorded the refund and says what tax it gave back.
     *
     * @param list<array{name: string, percent: string, amount: string, code?: string}> $taxes
     */
    public function taxed(Money $tax, array $taxes): void
    {
        $this->tax = $tax->toFixed();
        $this->taxes = $taxes;
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
    public function complete(string $providerReference, \DateTimeImmutable $at): bool
    {
        if (!$this->isOpen()) {
            return false;
        }
        $this->status = RefundStatus::Done;
        $this->providerReference = self::reference($providerReference) ?? $this->providerReference;
        $this->error = null;
        $this->settledAt = $at;

        return true;
    }

    /**
     * The provider took it, to finish later.
     *
     * @return bool false when it was accepted or settled before
     */
    public function accept(string $providerReference): bool
    {
        if ($this->status !== RefundStatus::Pending) {
            return false;
        }
        $this->status = RefundStatus::Accepted;
        $this->providerReference = self::reference($providerReference);
        $this->error = null;

        return true;
    }

    /** Sent and not finished: waiting for the provider's answer, or for the money it accepted to send. */
    public function isOpen(): bool
    {
        return \in_array($this->status, [RefundStatus::Pending, RefundStatus::Accepted], true);
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
        if (!$this->isOpen()) {
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
        $this->providerReference = self::reference($providerReference);
    }

    /**
     * A gateway that names no reference gives none: stored as none, as (gateway, reference) is unique and
     * RefundsPayments promises no reference, let alone a unique one.
     */
    private static function reference(string $providerReference): ?string
    {
        $providerReference = trim($providerReference);

        return $providerReference === '' ? null : mb_substr($providerReference, 0, 100);
    }
}
