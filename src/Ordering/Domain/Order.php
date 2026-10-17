<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A placed order: what was bought at which prices, where it goes, how it is paid, and where it stands.
 * Its id is the order number customers see; its key is the secret that lets a customer without an account
 * see it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\UniqueConstraint(name: 'order_key', columns: ['order_key'])]
#[ORM\Index(name: 'order_status', columns: ['status', 'placed_at'])]
#[ORM\Index(name: 'order_customer', columns: ['customer_id'])]
class Order
{
    public const int MAX_NOTE_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'order_key', length: 32)]
    private string $key;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(nullable: true)]
    private ?int $customerId;

    #[ORM\Column(length: 200)]
    private string $email;

    #[ORM\Embedded(columnPrefix: 'billing_')]
    private Address $billing;

    #[ORM\Embedded(columnPrefix: 'shipping_')]
    private Address $shippingAddress;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(nullable: true)]
    private ?int $shippingRateId;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $shippingName;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $shippingTotal;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $shippingTax;

    /** @var list<array{name: string, percent: string, amount: string}> */
    #[ORM\Column(type: 'json')]
    private array $taxes;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $subtotal;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $itemsTax;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $total;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $paymentGateway;

    #[ORM\Column(length: 100)]
    private string $paymentName;

    #[ORM\Column(type: 'text')]
    private string $paymentNote;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(type: 'text')]
    private string $note;

    #[ORM\Column]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(OrderDraft $draft)
    {
        if (preg_match('/^[a-f0-9]{32}$/', $draft->key) !== 1) {
            throw new \InvalidArgumentException('An order key is 32 hexadecimal characters.');
        }
        if ($draft->lines === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        if (filter_var($draft->email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($draft->email) > 200) {
            throw new OrderRuleViolation('An order needs a valid email address.', 'orillacart_invalid_email');
        }
        if (mb_strlen($draft->note) > self::MAX_NOTE_LENGTH) {
            throw new OrderRuleViolation(\sprintf('A note has up to %d characters.', self::MAX_NOTE_LENGTH), 'orillacart_invalid_note');
        }
        $shippingTotal = $draft->shipping->price ?? Money::zero();
        $shippingTax = $draft->shipping->tax ?? Money::zero();
        if (!$draft->subtotal->add($draft->itemsTax)->add($shippingTotal)->add($shippingTax)->equals($draft->total)) {
            throw new \LogicException('The order total is not the sum of its parts.');
        }
        if (($draft->shipping === null) !== ($draft->shippingAddress === null)) {
            throw new \LogicException('An order that ships has a shipping address and a way to ship, one that does not has neither.');
        }

        $this->key = $draft->key;
        $this->status = OrderStatus::PendingPayment;
        $this->customerId = $draft->customerId;
        $this->email = $draft->email;
        $this->billing = $draft->billing;
        $this->shippingAddress = $draft->shippingAddress ?? Address::none();
        $this->lines = new ArrayCollection();
        foreach ($draft->lines as $position => $line) {
            $this->lines->add(new OrderLine($this, $line, $position));
        }
        $this->shippingRateId = $draft->shipping?->rateId;
        $this->shippingName = $draft->shipping === null ? null : mb_substr("{$draft->shipping->carrier} – {$draft->shipping->rate}", 0, 200);
        $this->shippingTotal = $shippingTotal->toFixed();
        $this->shippingTax = $shippingTax->toFixed();
        $this->taxes = array_map(static fn(TaxLine $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount->toFixed()], $draft->taxes);
        $this->subtotal = $draft->subtotal->toFixed();
        $this->itemsTax = $draft->itemsTax->toFixed();
        $this->total = $draft->total->toFixed();
        $this->currency = $draft->currency;
        $this->paymentGateway = $draft->paymentGateway;
        $this->paymentName = mb_substr($draft->paymentName, 0, 100);
        $this->paymentNote = $draft->paymentNote;
        $this->note = trim($draft->note);
        $this->placedAt = $draft->placedAt;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The order has no number until it is saved.');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function billing(): Address
    {
        return $this->billing;
    }

    public function shippingAddress(): ?Address
    {
        return $this->shippingAddress->isEmpty() ? null : $this->shippingAddress;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn(OrderLine $a, OrderLine $b): int => $a->position() <=> $b->position());

        return $lines;
    }

    public function shippingName(): ?string
    {
        return $this->shippingName;
    }

    public function shippingTotal(): Money
    {
        return Money::of($this->shippingTotal);
    }

    public function shippingTax(): Money
    {
        return Money::of($this->shippingTax);
    }

    /** @return list<TaxLine> */
    public function taxes(): array
    {
        return array_map(static fn(array $tax): TaxLine => new TaxLine($tax['name'], $tax['percent'], Money::of($tax['amount'])), $this->taxes);
    }

    public function subtotal(): Money
    {
        return Money::of($this->subtotal);
    }

    public function itemsTax(): Money
    {
        return Money::of($this->itemsTax);
    }

    public function total(): Money
    {
        return Money::of($this->total);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function paymentGateway(): ?string
    {
        return $this->paymentGateway;
    }

    public function paymentName(): string
    {
        return $this->paymentName;
    }

    public function paymentNote(): string
    {
        return $this->paymentNote;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function paidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    /** The shop waits for an offline payment, or for the provider's review. */
    public function hold(): bool
    {
        if ($this->status === OrderStatus::OnHold) {
            return false;
        }
        $this->requireOpen('put on hold');
        $this->status = OrderStatus::OnHold;

        return true;
    }

    /**
     * Paid in full, or nothing to pay: the shop fulfils it.
     *
     * @return bool false when the order was already paid
     */
    public function markPaid(?string $transactionId, \DateTimeImmutable $at): bool
    {
        if ($this->paidAt !== null) {
            return false;
        }
        $this->requireOpen('marked paid');
        $this->status = OrderStatus::Processing;
        $this->transactionId = $transactionId;
        $this->paidAt = $at;

        return true;
    }

    /**
     * The payment failed. The stock the order took is returned to the caller, which puts it back.
     *
     * @return list<TakenItem> empty when the order was not open
     */
    public function fail(): array
    {
        return $this->close(OrderStatus::Failed);
    }

    /**
     * The customer or the shop gave up the order before it was paid.
     *
     * @return list<TakenItem> the stock to put back; empty when the order was not open
     */
    public function cancel(): array
    {
        return $this->close(OrderStatus::Cancelled);
    }

    /**
     * @return list<TakenItem>
     */
    private function close(OrderStatus $status): array
    {
        if (!$this->status->isOpen()) {
            return [];
        }
        $this->status = $status;
        $taken = [];
        foreach ($this->lines as $line) {
            array_push($taken, ...$line->stockTaken());
            $line->forgetStock();
        }

        return $taken;
    }

    private function requireOpen(string $change): void
    {
        if (!$this->status->isOpen()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot be %s.', $this->id(), str_replace('_', ' ', $this->status->value), $change), 'orillacart_order_closed');
        }
    }
}
