<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\References;

/**
 * One attempt to pay for an order through a gateway, with what the provider calls it. A payment ends once:
 * completed, failed or cancelled, and never changes after that.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'payment_reference', columns: ['gateway', 'reference'])]
#[ORM\Index(name: 'payment_order', columns: ['order_id'])]
#[ORM\Index(name: 'payment_open', columns: ['status', 'checked_at'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Pending;

    /** The provider's id of the payment session, such as a PayPal order id. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    /** The provider's id of the money movement, such as a PayPal capture id. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /** When the shop last asked the provider how it stands, for payments it heard nothing final of. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    public function __construct(
        #[ORM\Column]
        #[References('orders')]
        private int $orderId,
        #[ORM\Column(length: 40)]
        private string $gateway,
        Money $amount,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new PaymentRuleViolation('A payment is for more than nothing.', 'orillacart_invalid_payment_amount');
        }
        $this->amount = $amount->toFixed();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The payment has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function currency(): string
    {
        return $this->currency;
    }


    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function isSettled(): bool
    {
        return $this->status !== PaymentStatus::Pending;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function checked(\DateTimeImmutable $at): void
    {
        $this->checkedAt = $at;
    }

    /** The provider opened a session for it, such as a PayPal order. */
    public function refer(string $reference): void
    {
        $this->reference = $reference;
    }

    /**
     * @return bool false when the payment had already ended
     */
    public function complete(?string $transactionId, \DateTimeImmutable $at): bool
    {
        return $this->settle(PaymentStatus::Completed, $transactionId, $at);
    }

    public function fail(?string $transactionId, \DateTimeImmutable $at): bool
    {
        return $this->settle(PaymentStatus::Failed, $transactionId, $at);
    }

    public function cancel(\DateTimeImmutable $at): bool
    {
        return $this->settle(PaymentStatus::Cancelled, null, $at);
    }

    /** The provider holds the money for review; the payment stays pending with its transaction known. */
    public function hold(string $transactionId): void
    {
        if (!$this->isSettled()) {
            $this->transactionId = $transactionId;
        }
    }

    private function settle(PaymentStatus $status, ?string $transactionId, \DateTimeImmutable $at): bool
    {
        if ($this->isSettled()) {
            return false;
        }
        $this->status = $status;
        $this->transactionId = $transactionId ?? $this->transactionId;
        $this->settledAt = $at;

        return true;
    }
}
