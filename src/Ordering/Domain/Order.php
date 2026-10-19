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

    /** The amounts include tax, as the shop's prices did when it was priced: the total is not subtotal plus tax. */
    #[ORM\Column(options: ['default' => false])]
    private bool $pricesIncludeTax = false;

    /** The business customer's VAT number, prefix first: DE123456789. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vatNumber = null;

    /** Sold without VAT, the business customer accounting for it (reverse charge). */
    #[ORM\Column(options: ['default' => false])]
    private bool $reverseCharge = false;

    /** The language the customer ordered in (a WordPress locale such as de_DE), for what the shop writes them. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $locale = null;

    /** When the customer's details were erased, as a privacy request asked. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $anonymisedAt = null;

    /** Since when it waits for the payment it was last asked to pay: placing it, or paying it again later. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $awaitingPaymentSince = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(type: 'text')]
    private string $note;

    #[ORM\Column]
    private \DateTimeImmutable $placedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** Why the shop has to look at the order, such as a payment disputed after it was paid; null when nothing. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attention = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attentionSince = null;

    /** What coupons took off the items, in all. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $discountTotal = '0';

    /** @var list<array{code: string, description: string, amount: string, free_shipping: bool, automatic?: bool}> the coupons it used */
    #[ORM\Column(type: 'json')]
    private array $discounts = [];

    /** What was given back, in all. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedTotal = '0';

    /** The tax inside what was given back. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedTax = '0';

    /** What the customer paid: the total, unless a provider took another amount. Refunds give back no more. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $paidAmount = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $shippingRefunded = false;

    /** @var list<array{id: string, label: string, answer: string}>|null answers to add-ons' checkout fields, with the labels they had */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fields = null;

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
        $discountTotal = $draft->discountTotal ?? Money::zero();
        if (!self::addsUp($draft->subtotal, $discountTotal, $draft->itemsTax, $shippingTotal, $shippingTax, $draft->total, $draft->pricesIncludeTax)) {
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
        $this->discountTotal = $discountTotal->toFixed();
        $this->discounts = self::discountRows($draft->discounts);
        $this->currency = $draft->currency;
        $this->paymentGateway = $draft->paymentGateway;
        $this->paymentName = mb_substr($draft->paymentName, 0, 100);
        $this->paymentNote = $draft->paymentNote;
        $this->note = trim($draft->note);
        $this->placedAt = $draft->placedAt;
        $this->awaitingPaymentSince = $draft->placedAt;
        $this->pricesIncludeTax = $draft->pricesIncludeTax;
        $this->vatNumber = $draft->vatNumber === null ? null : mb_substr($draft->vatNumber, 0, 20);
        $this->reverseCharge = $draft->reverseCharge;
        $this->locale = $draft->locale === null || preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}$/', $draft->locale) !== 1 ? null : $draft->locale;
        $this->fields = self::fieldRows($draft->fields);
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

    /** What coupons took off the items, in all. */
    public function discountTotal(): Money
    {
        return Money::of($this->discountTotal);
    }

    /** @return list<OrderDiscount> */
    public function discounts(): array
    {
        return array_map(static fn(array $row): OrderDiscount => new OrderDiscount($row['code'], $row['description'], Money::of($row['amount']), $row['free_shipping'], $row['automatic'] ?? false), $this->discounts);
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

    public function attention(): ?string
    {
        return $this->attention;
    }

    public function attentionSince(): ?\DateTimeImmutable
    {
        return $this->attentionSince;
    }

    /**
     * Something the shop has to look at, whatever the status: the reasons add up until the shop marks it handled.
     *
     * @return bool false for a reason already waiting, as when a provider sends the same notification again
     */
    public function needsAttention(string $reason, \DateTimeImmutable $at): bool
    {
        $reason = trim($reason);
        if ($this->attention !== null && \in_array($reason, explode("\n", $this->attention), true)) {
            return false;
        }
        $this->attention = $this->attention === null ? $reason : $this->attention . "\n" . $reason;
        $this->attentionSince ??= $at;

        return true;
    }

    /** @return bool false when nothing needed attention */
    public function attended(): bool
    {
        if ($this->attention === null) {
            return false;
        }
        $this->attention = null;
        $this->attentionSince = null;

        return true;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function awaitingPaymentSince(): \DateTimeImmutable
    {
        return $this->awaitingPaymentSince ?? $this->placedAt;
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

    public function refundedTax(): Money
    {
        return Money::of($this->refundedTax);
    }

    /** What the customer paid; nothing before the payment. */
    public function paidAmount(): Money
    {
        return $this->paidAmount === null ? Money::zero() : Money::of($this->paidAmount);
    }

    /** What is left to give back: what was paid, less what went back. */
    public function refundable(): Money
    {
        return $this->paidAmount()->subtract($this->refundedTotal())->atLeastZero();
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
        if (!$this->isPaid()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is not paid; only paid orders are refunded.', $this->id()), 'orillacart_order_not_refundable');
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
     * @return RefundShare the tax given back, and the stock to put back: the refunded items', when the refund
     *                     restocks
     *
     * @throws OrderRuleViolation as checkRefund()
     */
    public function refund(OrderRefund $refund): RefundShare
    {
        $this->checkRefund($refund);
        $tax = $this->refundTax($refund);
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
        $this->refundedTax = $this->refundedTax()->add($tax)->toFixed();
        if ($this->refundable()->isZero()) {
            // The items not refunded one by one keep their stock: they may come back, and be restocked then.
            $this->status = OrderStatus::Refunded;
            $this->grants->clear();
        }

        return new RefundShare($tax, $this->taxShares($tax), $taken);
    }

    /**
     * The tax inside a refund's amount, and the amount spread over the lines: the share the tax is of what the
     * refund covers, the refunded items (by quantity) and shipping, or the whole order when it names neither.
     * Never more than is left of the order's tax; the refund that empties the order gives back all of it.
     */
    private function refundTax(OrderRefund $refund): Money
    {
        $gross = Money::zero();
        $tax = Money::zero();
        $covered = [];
        foreach ($this->lines as $line) {
            $quantity = $refund->quantities[$line->position()] ?? 0;
            if ($quantity > 0) {
                [$lineGross, $lineTax] = $this->lineShare($line, $quantity);
                $covered[] = [$line, $lineGross];
                $gross = $gross->add($lineGross);
                $tax = $tax->add($lineTax);
            }
        }
        if ($refund->shipping) {
            $gross = $gross->add($this->shippingTotal())->add($this->pricesIncludeTax ? Money::zero() : $this->shippingTax());
            $tax = $tax->add($this->shippingTax());
        }
        if ($gross->isZero()) {
            foreach ($this->lines as $line) {
                $covered[] = [$line, $this->lineShare($line, $line->quantity())[0]];
            }
            [$gross, $tax] = [$this->total(), $this->itemsTax()->add($this->shippingTax())];
        }
        foreach ($covered as [$line, $lineGross]) {
            $line->refunded($refund->amount->proportion($lineGross, $gross));
        }

        $taxLeft = $this->itemsTax()->add($this->shippingTax())->subtract($this->refundedTax())->atLeastZero();
        $share = $refund->amount->compare($this->refundable()) >= 0 ? $taxLeft : $tax->proportion($refund->amount, $gross);

        return $share->compare($taxLeft) > 0 ? $taxLeft : $share;
    }

    /**
     * What the customer paid for some of a line's items, and the tax inside it.
     *
     * @return array{Money, Money}
     */
    private function lineShare(OrderLine $line, int $quantity): array
    {
        $lineTax = $line->tax();
        $lineGross = $line->total()->subtract($line->discount())->add($this->pricesIncludeTax ? Money::zero() : $lineTax);
        $part = Money::of((string) $quantity);
        $whole = Money::of((string) $line->quantity());

        return [$lineGross->proportion($part, $whole), $lineTax->proportion($part, $whole)];
    }

    /**
     * A refund's tax spread over the order's tax lines, in the share each holds of the order's tax; the last takes
     * what rounding leaves.
     *
     * @return list<TaxLine>
     */
    private function taxShares(Money $tax): array
    {
        $lines = array_values(array_filter($this->taxes(), static fn(TaxLine $line): bool => !$line->amount->isZero()));
        if ($tax->isZero() || $lines === []) {
            return [];
        }
        $whole = array_reduce($lines, static fn(Money $sum, TaxLine $line): Money => $sum->add($line->amount), Money::zero());
        $shares = [];
        $left = $tax;
        foreach ($lines as $index => $line) {
            $amount = $index === \count($lines) - 1 ? $left : $tax->proportion($line->amount, $whole);
            $left = $left->subtract($amount);
            $shares[] = new TaxLine($line->name, $line->percent, $amount);
        }

        return $shares;
    }

    /**
     * Items of a refunded order came back after the money went, as after a refund of an amount alone: they go back
     * in stock, and count as refunded.
     *
     * @param array<int, int> $quantities by line position
     *
     * @return list<TakenItem> the stock to put back
     *
     * @throws OrderRuleViolation for an order not refunded, or more items than the line still holds in stock
     */
    public function restock(array $quantities): array
    {
        if ($this->status !== OrderStatus::Refunded) {
            throw new OrderRuleViolation(\sprintf('Order #%d is not refunded; its items go back in stock with a refund.', $this->id()), 'orillacart_order_not_refunded');
        }
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[$line->position()] = $line;
        }
        $taken = [];
        foreach ($quantities as $position => $quantity) {
            $line = $lines[$position] ?? throw new OrderRuleViolation(\sprintf('Order #%d has no line %d.', $this->id(), $position + 1), 'orillacart_invalid_refund_line');
            if ($quantity < 1 || $quantity > $line->restockable()) {
                throw new OrderRuleViolation(\sprintf('Line "%s" has %d items to put back in stock.', $line->name(), $line->restockable()), 'orillacart_invalid_refund_quantity');
            }
            array_push($taken, ...$line->refund($quantity, true));
        }

        return $taken;
    }

    /** Whether some of its items still hold stock that could be put back. */
    public function isRestockable(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->restockable() > 0) {
                return true;
            }
        }

        return false;
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
     * @return list<FieldAnswer>
     */
    public function fields(): array
    {
        return array_map(static fn(array $row): FieldAnswer => new FieldAnswer($row['id'], $row['label'], $row['answer']), $this->fields ?? []);
    }

    /**
     * Corrects answers the order has; a field it was never asked is not added.
     *
     * @param array<string, string> $answers by field id
     */
    public function answer(array $answers): void
    {
        $this->fields = self::fieldRows(array_map(
            static fn(FieldAnswer $field): FieldAnswer => isset($answers[$field->id]) ? new FieldAnswer($field->id, $field->label, trim($answers[$field->id])) : $field,
            $this->fields(),
        ));
    }

    /**
     * @param list<FieldAnswer> $fields
     *
     * @return list<array{id: string, label: string, answer: string}>|null
     */
    private static function fieldRows(array $fields): ?array
    {
        return $fields === [] ? null : array_map(static fn(FieldAnswer $field): array => ['id' => $field->id, 'label' => $field->label, 'answer' => $field->answer], $fields);
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
        if (!$this->isPaid() || !$this->grants->isEmpty()) {
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
     * @param list<LineDraft>     $lines
     * @param list<TaxLine>       $taxes
     * @param list<OrderDiscount> $discounts the coupons, priced again
     *
     * @throws OrderRuleViolation for an order not on hold, or one without items
     */
    public function replaceItems(array $lines, ?ShippingChoice $shipping, ?Address $shippingAddress, array $taxes, Money $subtotal, Money $itemsTax, Money $total, ?Money $discountTotal = null, array $discounts = [], bool $pricesIncludeTax = false): void
    {
        $discountTotal ??= Money::zero();
        $this->requireOnHold();
        if ($lines === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        $shippingTotal = $shipping->price ?? Money::zero();
        $shippingTax = $shipping->tax ?? Money::zero();
        if (!self::addsUp($subtotal, $discountTotal, $itemsTax, $shippingTotal, $shippingTax, $total, $pricesIncludeTax)) {
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
        $this->pricesIncludeTax = $pricesIncludeTax;
        $this->discountTotal = $discountTotal->toFixed();
        $this->discounts = self::discountRows($discounts);
    }

    /**
     * @param list<OrderDiscount> $discounts
     *
     * @return list<array{code: string, description: string, amount: string, free_shipping: bool, automatic: bool}>
     */
    private static function discountRows(array $discounts): array
    {
        return array_map(static fn(OrderDiscount $discount): array => ['code' => $discount->code, 'description' => $discount->description, 'amount' => $discount->amount->toFixed(), 'free_shipping' => $discount->freeShipping, 'automatic' => $discount->automatic], $discounts);
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
     * Paid in full, or nothing to pay. An order that waited for the payment is fulfilled now; one already being
     * fulfilled unpaid, such as cash on delivery, keeps its status.
     *
     * @return bool false when the order was already paid
     *
     * @throws OrderRuleViolation for a cancelled, failed or refunded order
     */
    public function markPaid(?string $transactionId, \DateTimeImmutable $at, ?Money $amount = null): bool
    {
        if ($this->paidAt !== null) {
            return false;
        }
        if ($this->status->isClosed()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot be marked paid.', $this->id(), strtolower($this->status->label())), 'orillacart_order_closed');
        }
        if ($this->status->isOpen()) {
            $this->status = OrderStatus::Processing;
        }
        $this->transactionId = $transactionId;
        $this->paidAt = $at;
        $this->paidAmount = ($amount ?? $this->total())->toFixed();

        return true;
    }

    /** The money came, and was not given back in full: what the customer bought is theirs, downloads included. */
    public function isPaid(): bool
    {
        return $this->paidAt !== null && $this->status !== OrderStatus::Refunded;
    }

    /**
     * The statuses the shop may move the order to: those of its status, and cancelling an unpaid order being
     * fulfilled (cash on delivery the customer did not take). A paid order is never cancelled: it leaves by
     * refunding it, so the shop never keeps money for an order it called off.
     *
     * @return list<OrderStatus>
     */
    public function nextStatuses(): array
    {
        $next = $this->status->next();
        if ($this->paidAt === null && \in_array($this->status, [OrderStatus::Processing, OrderStatus::Shipped], true)) {
            $next[] = OrderStatus::Cancelled;
        }

        return $this->paidAt === null ? $next : array_values(array_filter($next, static fn(OrderStatus $status): bool => $status !== OrderStatus::Cancelled));
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
        return $this->status->isOpen() || ($this->paidAt === null && $this->status->isFulfilling() && $this->status !== OrderStatus::Completed) ? $this->close(OrderStatus::Cancelled) : [];
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
        if (!\in_array($status, $this->nextStatuses(), true)) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot become %s.', $this->id(), strtolower($this->status->label()), strtolower($status->label())), 'orillacart_invalid_status_change');
        }

        switch ($status) {
            case OrderStatus::OnHold:
                $this->hold();

                return [];
            case OrderStatus::Completed:
                // Done: the courier handed over the cash of an order paid on delivery, if nothing came before.
                $this->status = OrderStatus::Completed;
                $this->paidAt ??= $at;

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
    private function close(OrderStatus $status): array
    {
        $this->status = $status;
        $this->grants->clear();
        $taken = [];
        foreach ($this->lines as $line) {
            array_push($taken, ...$line->releaseStock());
        }

        return $taken;
    }

    /**
     * A payment came for an order that was already paid: the shop marked it paid by hand before the provider's
     * payment arrived, or a second payment went through. The provider's transaction is kept when the order had
     * none.
     *
     * @return bool whether the order already had another transaction: two payments, one of them to give back
     */
    public function paidAgain(string $transactionId): bool
    {
        if ($this->transactionId === null) {
            $this->transactionId = $transactionId;

            return false;
        }

        return $this->transactionId !== $transactionId;
    }

    /** Whether the customer can pay it now: unpaid, and waiting for the money or failed to pay. */
    public function isPayable(): bool
    {
        return $this->paidAt === null && \in_array($this->status, [OrderStatus::PendingPayment, OrderStatus::OnHold, OrderStatus::Failed], true);
    }

    /**
     * The customer pays the order now, with the method chosen: it waits for that payment from now. A failed
     * order takes again the stock it gave back.
     *
     * @param \Closure(array<int, array<string, int>>): array<int, list<TakenItem>> $take takes, for each line by its
     *                                                                                 index, the quantity of each
     *                                                                                 item; it throws, taking
     *                                                                                 nothing, when one is short
     *
     * @param-immediately-invoked-callable $take
     *
     * @throws OrderRuleViolation for an order that cannot be paid now
     */
    public function awaitPayment(string $gateway, string $name, string $note, \DateTimeImmutable $at, \Closure $take): void
    {
        if (!$this->isPayable()) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot be paid now.', $this->id(), strtolower($this->status->label())), 'orillacart_order_not_payable');
        }
        if ($this->status === OrderStatus::Failed) {
            $lines = array_values($this->lines->toArray());
            $taken = $take(array_map(static fn(OrderLine $line): array => $line->stockReleased(), $lines));
            foreach ($lines as $index => $line) {
                $line->retake($taken[$index] ?? []);
            }
        }
        $this->status = OrderStatus::PendingPayment;
        $this->paymentGateway = $gateway;
        $this->paymentName = mb_substr($name, 0, 100);
        $this->paymentNote = $note;
        $this->awaitingPaymentSince = $at;
    }

    /**
     * The money came after the order was cancelled or failed unpaid, as when a customer approves at the provider
     * after the payment window closed: the order opens again, paid, taking back the stock its lines gave back.
     *
     * @param \Closure(array<int, array<string, int>>): array<int, list<TakenItem>> $take takes, for each line by its
     *                                                                                 index, the quantity of each
     *                                                                                 item; it throws, taking
     *                                                                                 nothing, when one is short
     *
     * @param-immediately-invoked-callable $take
     *
     * @throws OrderRuleViolation for an order that did not close unpaid
     */
    public function reopenPaid(?string $transactionId, \DateTimeImmutable $at, \Closure $take, ?Money $amount = null): void
    {
        if (!\in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Failed], true) || $this->paidAt !== null) {
            throw new OrderRuleViolation(\sprintf('Order #%d is %s and cannot be opened again.', $this->id(), strtolower($this->status->label())), 'orillacart_order_not_reopenable');
        }
        $lines = array_values($this->lines->toArray());
        $taken = $take(array_map(static fn(OrderLine $line): array => $line->stockReleased(), $lines));
        foreach ($lines as $index => $line) {
            $line->retake($taken[$index] ?? []);
        }
        $this->status = OrderStatus::Processing;
        $this->transactionId = $transactionId;
        $this->paidAt = $at;
        $this->paidAmount = ($amount ?? $this->total())->toFixed();
    }

    public function pricesIncludeTax(): bool
    {
        return $this->pricesIncludeTax;
    }

    /**
     * Erases the customer from the order, as a privacy request asks: name, email, phone, street, city, postcode,
     * their note, their answers to checkout questions and the account it belongs to. What the books must keep
     * stays: the items, amounts, taxes, the country and a business's VAT number. The downloads go: there is
     * nobody to give them to.
     *
     * @return bool false when it was erased before
     */
    public function anonymise(\DateTimeImmutable $at): bool
    {
        if ($this->anonymisedAt !== null) {
            return false;
        }
        $this->email = 'anonymised@invalid';
        $this->billing = $this->billing->anonymised();
        $this->shippingAddress = $this->shippingAddress->isEmpty() ? $this->shippingAddress : $this->shippingAddress->anonymised();
        $this->customerId = null;
        $this->note = '';
        $this->fields = [];
        $this->grants->clear();
        $this->anonymisedAt = $at;

        return true;
    }

    public function anonymisedAt(): ?\DateTimeImmutable
    {
        return $this->anonymisedAt;
    }

    public function vatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function reverseCharge(): bool
    {
        return $this->reverseCharge;
    }

    /** The customer's language; null for the site's. */
    public function locale(): ?string
    {
        return $this->locale;
    }

    /** The total is the subtotal less discounts plus shipping, and plus tax unless the amounts include it. */
    private static function addsUp(Money $subtotal, Money $discount, Money $itemsTax, Money $shipping, Money $shippingTax, Money $total, bool $includesTax): bool
    {
        $sum = $subtotal->subtract($discount)->add($shipping);

        return ($includesTax ? $sum : $sum->add($itemsTax)->add($shippingTax))->equals($total);
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
