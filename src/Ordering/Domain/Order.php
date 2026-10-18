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

    /** @var Collection<int, DownloadGrant> */
    #[ORM\OneToMany(targetEntity: DownloadGrant::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['linePosition' => 'ASC', 'id' => 'ASC'])]
    private Collection $grants;

    /** @var Collection<int, OrderNote> */
    #[ORM\OneToMany(targetEntity: OrderNote::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $notes;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingOption;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $shippingName;

    /** The courier's id of the office or locker the order goes to, for a service to one. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pickupPointId = null;

    /** Its name and address. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pickupPoint = null;

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

    /** What was given back, in all. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedTotal = '0';

    #[ORM\Column(options: ['default' => false])]
    private bool $shippingRefunded = false;

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
        $this->notes = new ArrayCollection();
        $this->grants = new ArrayCollection();
        foreach ($draft->lines as $position => $line) {
            $this->lines->add(new OrderLine($this, $line, $position));
        }
        $this->shippingOption = $draft->shipping?->option;
        $this->shippingName = $draft->shipping === null ? null : mb_substr("{$draft->shipping->carrier} – {$draft->shipping->rate}", 0, 200);
        $this->pickupPointId = $draft->shipping?->pickupPointId;
        $this->pickupPoint = $draft->shipping?->pickupPoint === null ? null : mb_substr($draft->shipping->pickupPoint, 0, 255);
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

    public function pickupPointId(): ?string
    {
        return $this->pickupPointId;
    }

    /** The office or locker the order goes to: its name and address. */
    public function pickupPoint(): ?string
    {
        return $this->pickupPoint;
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

    public function refundedTotal(): Money
    {
        return Money::of($this->refundedTotal);
    }

    /** What is left to give back. */
    public function refundable(): Money
    {
        return $this->total()->subtract($this->refundedTotal())->atLeastZero();
    }

    public function isShippingRefunded(): bool
    {
        return $this->shippingRefunded;
    }

    /**
     * Whether the refund fits the order: it is paid, the amount is no more than is left, and the items and the
     * shipping were not refunded before. Checked before any money moves.
     *
     * @throws OrderRuleViolation
     */
    public function checkRefund(OrderRefund $refund): void
    {
        if (!$this->status->isPaid()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s; only paid orders are refunded.', $this->id(), strtolower($this->status->label())), 'orillacart_order_not_refundable');
        }
        if ($refund->amount->isNegative() || $refund->amount->isZero()) {
            throw new OrderRuleViolation('A refund is for more than nothing.', 'orillacart_invalid_refund_amount');
        }
        if ($refund->amount->compare($this->refundable()) > 0) {
            throw new OrderRuleViolation(\sprintf('At most %s %s is left to refund.', $this->refundable(), $this->currency), 'orillacart_invalid_refund_amount');
        }
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[$line->position()] = $line;
        }
        foreach ($refund->quantities as $position => $quantity) {
            $line = $lines[$position] ?? throw new OrderRuleViolation(\sprintf('Order #%d has no line %d.', $this->id(), $position + 1), 'orillacart_invalid_refund_line');
            if ($quantity < 1 || $quantity > $line->refundableQuantity()) {
                throw new OrderRuleViolation(\sprintf('Line "%s" has %d items left to refund.', $line->name(), $line->refundableQuantity()), 'orillacart_invalid_refund_quantity');
            }
        }
        if ($refund->shipping && ($this->shippingRefunded || $this->shippingName === null)) {
            throw new OrderRuleViolation('The shipping was refunded before, or the order ships nothing.', 'orillacart_invalid_refund_shipping');
        }
    }

    /**
     * Records money given back. Refunded items stop their downloads once none of the line is left; an order
     * refunded in full becomes Refunded, and the stock of what was not refunded item by item stays sold.
     *
     * @return list<TakenItem> the stock to put back: the refunded items', when the refund restocks
     *
     * @throws OrderRuleViolation as checkRefund()
     */
    public function refund(OrderRefund $refund): array
    {
        $this->checkRefund($refund);
        $taken = [];
        foreach ($this->lines as $line) {
            $quantity = $refund->quantities[$line->position()] ?? 0;
            if ($quantity > 0) {
                array_push($taken, ...$line->refund($quantity, $refund->restock));
                if ($line->refundableQuantity() === 0) {
                    foreach ($this->grants as $grant) {
                        if ($grant->linePosition() === $line->position()) {
                            $this->grants->removeElement($grant);
                        }
                    }
                }
            }
        }
        $this->shippingRefunded = $this->shippingRefunded || $refund->shipping;
        $this->refundedTotal = $this->refundedTotal()->add($refund->amount)->toFixed();
        if ($this->refundable()->isZero()) {
            array_push($taken, ...$this->close(OrderStatus::Refunded, false));
        }

        return $taken;
    }

    /**
     * @return list<OrderNote> oldest first
     */
    public function notes(): array
    {
        // Loaded in time order; new notes are added at the end.
        return array_values($this->notes->toArray());
    }

    public function addNote(string $text, NoteAuthor $author, bool $forCustomer, \DateTimeImmutable $at, ?int $userId = null): OrderNote
    {
        $note = new OrderNote($this, $text, $author, $forCustomer, $at, $userId);
        $this->notes->add($note);

        return $note;
    }

    /**
     * The customer's details as the shop corrected them. The shipping address stays only on orders that ship.
     *
     * @throws OrderRuleViolation for an invalid email, or a shipping address on an order that ships nothing
     */
    public function changeContact(string $email, Address $billing, ?Address $shippingAddress): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 200) {
            throw new OrderRuleViolation('An order needs a valid email address.', 'orillacart_invalid_email');
        }
        if (($shippingAddress === null) !== ($this->shippingName === null)) {
            throw new OrderRuleViolation($this->shippingName === null ? 'This order ships nothing, so it has no shipping address.' : 'This order ships, so it needs a shipping address.', 'orillacart_invalid_address');
        }
        $this->email = $email;
        $this->billing = $billing;
        $this->shippingAddress = $shippingAddress ?? Address::none();
    }

    /**
     * @return list<DownloadGrant>
     */
    public function grants(): array
    {
        return array_values($this->grants->toArray());
    }

    /**
     * Gives the buyer their downloads, once the order is paid: one secret link per file of each line, with the
     * product's limit and expiry counted from now.
     *
     * @param \Closure(): string $token makes a new secret token
     *
     * @return bool whether any download was granted
     */
    public function grantDownloads(\Closure $token, \DateTimeImmutable $now): bool
    {
        if (!$this->status->isPaid() || !$this->grants->isEmpty()) {
            return false;
        }
        foreach ($this->lines as $line) {
            foreach ($line->downloads() as $file) {
                $expiry = $line->downloadExpiryDays();
                $this->grants->add(new DownloadGrant(
                    $this,
                    $token(),
                    $line->position(),
                    $file->fileId,
                    $file->name,
                    $file->storageKey,
                    $file->mimeType,
                    $line->downloadLimit(),
                    $expiry === null ? null : $now->modify("+{$expiry} days"),
                ));
            }
        }

        return !$this->grants->isEmpty();
    }

    /** The customer made an account after ordering: the order is theirs. */
    public function belongTo(int $customerId): void
    {
        $this->customerId ??= $customerId;
    }

    /** Moves the order to another customer's account, or to none: a guest's order. */
    public function changeCustomer(?int $customerId): void
    {
        $this->customerId = $customerId;
    }

    /**
     * Frees the stock of an order on hold whose items the shop is about to change.
     *
     * @return list<TakenItem> the stock to put back
     *
     * @throws OrderRuleViolation for an order not on hold
     */
    public function releaseStock(): array
    {
        $this->requireOnHold();
        $taken = [];
        foreach ($this->lines as $line) {
            array_push($taken, ...$line->stockTaken());
            $line->forgetStock();
        }

        return $taken;
    }

    /**
     * Replaces what an order on hold contains, as the shop repriced it. Only orders waiting for an offline
     * payment change: a paid order, or one being paid at a provider, keeps what was paid for.
     *
     * @param list<LineDraft> $lines
     * @param list<TaxLine>   $taxes
     *
     * @throws OrderRuleViolation for an order not on hold, or one without items
     */
    public function replaceItems(array $lines, ?ShippingChoice $shipping, ?Address $shippingAddress, array $taxes, Money $subtotal, Money $itemsTax, Money $total): void
    {
        $this->requireOnHold();
        if ($lines === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        $shippingTotal = $shipping->price ?? Money::zero();
        $shippingTax = $shipping->tax ?? Money::zero();
        if (!$subtotal->add($itemsTax)->add($shippingTotal)->add($shippingTax)->equals($total)) {
            throw new \LogicException('The order total is not the sum of its parts.');
        }
        if (($shipping === null) !== ($shippingAddress === null)) {
            throw new \LogicException('An order that ships has a shipping address and a way to ship, one that does not has neither.');
        }
        $this->lines->clear();
        foreach ($lines as $position => $line) {
            $this->lines->add(new OrderLine($this, $line, $position));
        }
        $this->shippingAddress = $shippingAddress ?? Address::none();
        $this->shippingOption = $shipping?->option;
        $this->shippingName = $shipping === null ? null : mb_substr("{$shipping->carrier} – {$shipping->rate}", 0, 200);
        $this->pickupPointId = $shipping?->pickupPointId;
        $this->pickupPoint = $shipping?->pickupPoint === null ? null : mb_substr($shipping->pickupPoint, 0, 255);
        $this->shippingTotal = $shippingTotal->toFixed();
        $this->shippingTax = $shippingTax->toFixed();
        $this->taxes = array_map(static fn(TaxLine $tax): array => ['name' => $tax->name, 'percent' => $tax->percent, 'amount' => $tax->amount->toFixed()], $taxes);
        $this->subtotal = $subtotal->toFixed();
        $this->itemsTax = $itemsTax->toFixed();
        $this->total = $total->toFixed();
    }

    public function shippingOption(): ?string
    {
        return $this->shippingOption;
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
     * @return list<TakenItem> empty when the order was not open: a payment that fails late changes nothing
     */
    public function fail(): array
    {
        return $this->status->isOpen() ? $this->close(OrderStatus::Failed) : [];
    }

    /**
     * The customer gave up paying, or never finished.
     *
     * @return list<TakenItem> the stock to put back; empty when the order was not open
     */
    public function cancel(): array
    {
        return $this->status->isOpen() ? $this->close(OrderStatus::Cancelled) : [];
    }

    /**
     * The shop moves the order along, as its admin screen allows: see OrderStatus::next(). Cancelling puts the
     * stock back. An order becomes Refunded only by refunding it (refund()), so the money goes back with it.
     *
     * @return list<TakenItem> the stock to put back
     *
     * @throws OrderRuleViolation for a move the order's status does not allow
     */
    public function moveTo(OrderStatus $status, \DateTimeImmutable $at): array
    {
        if (!\in_array($status, $this->status->next(), true)) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot become %s.', $this->id(), strtolower($this->status->label()), strtolower($status->label())), 'orillacart_invalid_status_change');
        }

        switch ($status) {
            case OrderStatus::OnHold:
                $this->hold();

                return [];
            case OrderStatus::Processing:
                $this->markPaid($this->transactionId, $at);

                return [];
            case OrderStatus::Cancelled:
                return $this->close(OrderStatus::Cancelled);
            default:
                $this->status = $status;

                return [];
        }
    }

    /**
     * @return list<TakenItem>
     */
    private function close(OrderStatus $status, bool $restock = true): array
    {
        $this->status = $status;
        $this->grants->clear();
        $taken = [];
        foreach ($this->lines as $line) {
            if ($restock) {
                array_push($taken, ...$line->stockTaken());
            }
            $line->forgetStock();
        }

        return $taken;
    }

    private function requireOnHold(): void
    {
        if ($this->status !== OrderStatus::OnHold) {
            throw new OrderRuleViolation(\sprintf('Only orders on hold can change their items; order #%d is %s.', $this->id(), strtolower($this->status->label())), 'orillacart_order_not_editable');
        }
    }

    private function requireOpen(string $change): void
    {
        if (!$this->status->isOpen()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot be %s.', $this->id(), strtolower($this->status->label()), $change), 'orillacart_order_closed');
        }
    }
}
