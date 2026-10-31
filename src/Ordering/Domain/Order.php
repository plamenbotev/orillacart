<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;

/**
 * A placed order: what was bought at which prices, where it goes, how it is paid, and where it stands.
 * Its id is the order number customers see. It keeps no secret: a customer without an account sees it through a
 * signed link (ReceiptLinks).
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'order_status', columns: ['status', 'placed_at'])]
#[ORM\Index(name: 'order_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'order_delivery', columns: ['delivery_key', 'delivery_date'])]
#[ORM\Index(name: 'order_email', columns: ['email'])]
#[ORM\Index(name: 'order_attention', columns: ['attention_since'])]
#[ORM\Index(name: 'order_total', columns: ['total', 'id'])]
class Order
{
    public const int MAX_NOTE_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    /**
     * Its words in the shop's own language where the customer read others (design 51): the way it ships, the pickup
     * place, the way to pay and the answers' questions and choices, by field id. The customer's stay the order's.
     *
     * @var array{shipping_name?: string, pickup_point?: string, payment_name?: string, fields?: array<string, array{label: string, answer: string}>}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $shopWords = null;




    /** The day the customer chose for delivery, in the shop's calendar. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveryDate = null;

    /** The time of day they chose: "09:00–12:00". */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $deliverySlot = null;

    /** The carrier and slot it counts against, "5:morning", for slots that take so many orders a day. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $deliveryKey = null;






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

    /** When it first left for the customer: shipped, or completed without shipping first. Returns count from it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    /** Why the shop has to look at the order, such as a payment disputed after it was paid; null when nothing. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $attention = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attentionSince = null;

    /** @var array{reason: string, certificate: string}|null the customer's exemption from tax that covered the order */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $taxExemption = null;

    /** The tax service that taxed the order, by its id; null for the shop's own rates. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $taxProvider = null;

    /**
     * The import scheme its goods came under ("ioss"), and the shop's number there when it charged the tax at
     * checkout; without a number the customer pays it at the border.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $importScheme = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $importNumber = null;

    /**
     * Priced in another currency than the shop's: the shop's then, and how many units of the order's currency one
     * of it made, so its invoices can show the VAT in the shop's currency too.
     */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $shopCurrency = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8, nullable: true)]
    private ?string $exchangeRate = null;

    /** The business customer's own reference for the order (a purchase order number, a Leitweg-ID). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $buyerReference = null;

    /** The tax service's own reference of the sale, once it recorded it. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $taxReference = null;

    /** What gift cards and store credit paid of the total; the rest is paid the way the customer chose. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $giftCardTotal = '0';

    /** @var list<array{label: string, amount: string}> each gift card or store credit that paid, as the customer saw it */
    #[ORM\Column(type: 'json')]
    private array $giftCards = [];

    /** What was given back, in all. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedTotal = '0';

    /** The tax inside what was given back. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedTax = '0';

    /** What the customer paid: the total, unless a provider took another amount. Refunds give back no more. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $paidAmount = null;

    /** @var list<array{id: string, label: string, answer: string}>|null answers to add-ons' checkout fields, with the labels they had */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fields = null;

    public function __construct(OrderDraft $draft)
    {
        if ($draft->lines === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        if (filter_var($draft->email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($draft->email) > 200) {
            throw new OrderRuleViolation('An order needs a valid email address.', 'orillacart_invalid_email');
        }
        if (mb_strlen($draft->note) > self::MAX_NOTE_LENGTH) {
            throw new OrderRuleViolation('A note has up to %d characters.', 'orillacart_invalid_note', values: [self::MAX_NOTE_LENGTH]);
        }
        $items = array_filter($draft->lines, static fn(LineDraft $line): bool => $line->type->isItem());
        if ($items === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        if (!self::addsUp($draft->lines, $draft->total, $draft->pricesIncludeTax)) {
            throw new \LogicException('The order total is not the sum of its parts.');
        }
        if ((array_filter($draft->lines, static fn(LineDraft $line): bool => $line->type === LineType::Shipping) === []) !== ($draft->shipping === null)) {
            throw new \LogicException('An order that ships has a shipping line, one that does not has none.');
        }
        if (($draft->shipping === null) !== ($draft->shippingAddress === null)) {
            throw new \LogicException('An order that ships has a shipping address and a way to ship, one that does not has neither.');
        }

        $this->status = OrderStatus::PendingPayment;
        $this->customerId = $draft->customerId;
        $this->email = $draft->email;
        $this->billing = $draft->billing;
        $this->shippingAddress = $draft->shippingAddress ?? Address::none();
        $this->lines = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->grants = new ArrayCollection();
        foreach (self::ordered($draft->lines) as $position => $line) {
            $this->lines->add(OrderLine::of($this, $line, $position));
        }
        $this->shippingOption = $draft->shipping?->option;
        $this->shippingName = $draft->shipping === null ? null : mb_substr("{$draft->shipping->carrier} – {$draft->shipping->rate}", 0, 200);
        $this->pickupPointId = $draft->shipping?->pickupPointId;
        $this->pickupPoint = $draft->shipping?->pickupPoint === null ? null : mb_substr($draft->shipping->pickupPoint, 0, 255);
        if ($draft->delivery !== null && $draft->shipping === null) {
            throw new \LogicException('An order that ships nothing has no delivery day.');
        }
        $this->deliveryDate = $draft->delivery?->date;
        $this->deliverySlot = $draft->delivery?->slot;
        $this->deliveryKey = $draft->delivery?->key;
        $this->total = $draft->total->toFixed();
        $this->taxExemption = $draft->taxExemption;
        $this->taxProvider = $draft->taxProvider === null ? null : mb_substr($draft->taxProvider, 0, 40);
        $this->importScheme = $draft->importScheme === null ? null : mb_substr($draft->importScheme, 0, 10);
        $this->importNumber = $draft->importScheme === null || $draft->importNumber === null ? null : mb_substr($draft->importNumber, 0, 20);
        $reference = $draft->buyerReference === null ? '' : trim((string) preg_replace('/[\x00-\x1F<>]/u', '', $draft->buyerReference));
        $this->buyerReference = $reference === '' ? null : mb_substr($reference, 0, 100);
        if ($draft->shopCurrency !== null && $draft->exchangeRate !== null && $draft->shopCurrency !== $draft->currency) {
            if (preg_match('/^[A-Z]{3}$/', $draft->shopCurrency) !== 1 || preg_match('/^\d{1,10}(\.\d{1,8})?$/', $draft->exchangeRate) !== 1 || (float) $draft->exchangeRate <= 0) {
                throw new OrderRuleViolation('An exchange rate is a positive number with up to eight decimals.', 'orillacart_invalid_exchange_rate');
            }
            $this->shopCurrency = $draft->shopCurrency;
            $this->exchangeRate = $draft->exchangeRate;
        }
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
        $this->shipsInShopWords($draft->shipping);
        $this->shopWord('payment_name', $draft->shopPaymentName === null || $draft->shopPaymentName === $this->paymentName ? null : mb_substr($draft->shopPaymentName, 0, 100));
        $fields = array_intersect_key($draft->shopFields, array_flip(array_map(static fn(FieldAnswer $field): string => $field->id, $this->fields())));
        if ($fields !== []) {
            $this->shopWords = [...$this->shopWords ?? [], 'fields' => $fields];
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The order has no number until it is saved.');
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

    /**
     * The items, in their order.
     *
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return array_values(array_filter($this->allLines(), static fn(OrderLine $line): bool => $line->isItem()));
    }

    /**
     * Every line: the items, then the shipping, then the fees.
     *
     * @return list<OrderLine>
     */
    public function allLines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn(OrderLine $a, OrderLine $b): int => $a->position() <=> $b->position());

        return $lines;
    }

    /** What shipping costs, as a line; null for an order that ships nothing. */
    public function shippingLine(): ?ShippingLine
    {
        foreach ($this->allLines() as $line) {
            if ($line instanceof ShippingLine) {
                return $line;
            }
        }

        return null;
    }

    /** @return list<FeeLine> */
    public function feeLines(): array
    {
        return array_values(array_filter($this->allLines(), static fn(OrderLine $line): bool => $line instanceof FeeLine));
    }

    public function shippingName(): ?string
    {
        return $this->shippingName;
    }

    /** As the shop reads it: in its own language, where the customer read another (design 51). */
    public function shopShippingName(): ?string
    {
        return $this->shopWords['shipping_name'] ?? $this->shippingName;
    }

    public function shopPickupPoint(): ?string
    {
        return $this->shopWords['pickup_point'] ?? $this->pickupPoint;
    }

    public function shopPaymentName(): string
    {
        return $this->shopWords['payment_name'] ?? $this->paymentName;
    }

    /**
     * The answers as the shop reads them: their questions and choices in its own language.
     *
     * @return list<FieldAnswer>
     */
    public function shopFields(): array
    {
        $own = $this->shopWords['fields'] ?? [];

        return array_map(static fn(FieldAnswer $field): FieldAnswer => isset($own[$field->id]) ? new FieldAnswer($field->id, $own[$field->id]['label'], $own[$field->id]['answer']) : $field, $this->fields());
    }

    /** The shop's words for the way it ships now, or none where they are the customer's. */
    private function shipsInShopWords(?ShippingChoice $shipping): void
    {
        $name = $shipping?->shopName();
        $this->shopWord('shipping_name', $name === null ? null : mb_substr($name, 0, 200));
        $point = $shipping?->shopPickupPoint;
        $this->shopWord('pickup_point', $point === null || $point === $shipping?->pickupPoint ? null : mb_substr($point, 0, 255));
    }

    /**
     * @param 'shipping_name'|'pickup_point'|'payment_name' $key
     */
    private function shopWord(string $key, ?string $value): void
    {
        $words = $this->shopWords ?? [];
        if ($value === null) {
            unset($words[$key]);
        } else {
            $words[$key] = $value;
        }
        $this->shopWords = $words === [] ? null : $words;
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
        return $this->shippingLine()?->net() ?? Money::zero();
    }

    public function shippingTax(): Money
    {
        return $this->shippingLine()?->tax() ?? Money::zero();
    }

    /** The day and time of day the customer chose for delivery; null when they chose none. */
    public function delivery(): ?DeliveryChoice
    {
        return $this->deliveryDate === null ? null : new DeliveryChoice($this->deliveryDate, $this->deliverySlot, $this->deliveryKey);
    }

    /** @return list<OrderFee> */
    public function fees(bool $inShopWords = false): array
    {
        return array_map(static fn(OrderLine $line): OrderFee => new OrderFee($inShopWords ? $line->shopName() : $line->name(), $line->net()), $this->feeLines());
    }

    public function feesTotal(): Money
    {
        return self::sum($this->feeLines(), static fn(OrderLine $line): Money => $line->net());
    }

    public function feesTax(): Money
    {
        return self::sum($this->feeLines(), static fn(OrderLine $line): Money => $line->tax());
    }

    /**
     * The taxes of the whole order, one per rate as invoices list them: the lines' added up.
     *
     * @return list<TaxLine>
     */
    public function taxes(): array
    {
        $taxes = [];
        foreach ($this->allLines() as $line) {
            foreach ($line->taxes() as $tax) {
                $key = $tax->name() . '|' . $tax->percent();
                $taxes[$key] = new TaxLine($tax->name(), $tax->percent(), ($taxes[$key]->amount ?? Money::zero())->add($tax->amount()));
            }
        }

        return array_values(array_filter($taxes, static fn(TaxLine $tax): bool => !$tax->amount->isZero()));
    }

    /** @return list<OrderTax> every rate on every line */
    public function taxRows(): array
    {
        return array_merge([], ...array_map(static fn(OrderLine $line): array => $line->taxes(), $this->allLines()));
    }

    /** The items before tax and before the changes to their prices. */
    public function subtotal(): Money
    {
        return self::sum($this->lines(), static fn(OrderLine $line): Money => $line->total());
    }

    public function itemsTax(): Money
    {
        return self::sum($this->lines(), static fn(OrderLine $line): Money => $line->tax());
    }

    /** All the tax charged: on the items, the shipping and the fees. */
    public function tax(): Money
    {
        return self::sum($this->allLines(), static fn(OrderLine $line): Money => $line->tax());
    }

    /** @return array{reason: string, certificate: string}|null */
    public function taxExemption(): ?array
    {
        return $this->taxExemption;
    }

    public function buyerReference(): ?string
    {
        return $this->buyerReference;
    }

    public function shopCurrency(): ?string
    {
        return $this->shopCurrency;
    }

    public function exchangeRate(): ?string
    {
        return $this->exchangeRate === null ? null : rtrim(rtrim($this->exchangeRate, '0'), '.');
    }

    public function importScheme(): ?string
    {
        return $this->importScheme;
    }

    public function importNumber(): ?string
    {
        return $this->importNumber;
    }

    public function taxProvider(): ?string
    {
        return $this->taxProvider;
    }

    public function taxReference(): ?string
    {
        return $this->taxReference;
    }

    /** A tax service recorded the sale under its reference. */
    public function taxRecorded(string $provider, string $reference): void
    {
        $this->taxProvider = mb_substr($provider, 0, 40);
        $this->taxReference = mb_substr($reference, 0, 100);
    }

    public function total(): Money
    {
        return Money::of($this->total);
    }

    /** What gift cards and store credit paid of the total. */
    public function giftCardTotal(): Money
    {
        return Money::of($this->giftCardTotal);
    }

    /** @return list<array{label: string, amount: Money}> */
    public function giftCards(): array
    {
        return array_map(static fn(array $row): array => ['label' => $row['label'], 'amount' => Money::of($row['amount'])], $this->giftCards);
    }

    /** What is left to pay the way the customer chose: the total, less what gift cards paid. */
    public function amountDue(): Money
    {
        return $this->total()->subtract($this->giftCardTotal());
    }

    /**
     * Gift cards or store credit pay part of the total, or all of it, as checkout took it from them. Only an order
     * waiting for its payment takes them, once.
     *
     * @param list<array{label: string, amount: Money}> $cards
     *
     * @throws OrderRuleViolation
     */
    public function payWithGiftCards(array $cards): void
    {
        $sum = array_reduce($cards, static fn(Money $sum, array $card): Money => $sum->add($card['amount']), Money::zero());
        if ($this->status !== OrderStatus::PendingPayment || $this->paidAt !== null || $this->giftCards !== []) {
            throw new OrderRuleViolation('Order #%d takes gift cards only when it is placed.', 'orillacart_order_gift_cards', values: [$this->id()]);
        }
        if ($sum->isNegative() || $sum->compare($this->total()) > 0) {
            throw new OrderRuleViolation('Gift cards pay no more than the order costs.', 'orillacart_order_gift_cards');
        }
        $this->giftCardTotal = $sum->toFixed();
        $this->giftCards = array_map(static fn(array $card): array => ['label' => mb_substr($card['label'], 0, 100), 'amount' => $card['amount']->toFixed()], $cards);
    }

    /**
     * The gift cards that paid part of the order no longer had all of it when it was opened again: what they could
     * not pay again is left to pay the customer's way, the cards' share and what was paid count only what came,
     * and the shop looks at it.
     *
     * @return Money the share the cards no longer pay
     */
    public function giftCardsFellShort(Money $short, string $reason, \DateTimeImmutable $at): Money
    {
        $short = $short->compare($this->giftCardTotal()) > 0 ? $this->giftCardTotal() : $short;
        if ($short->isZero() || $short->isNegative()) {
            return Money::zero();
        }
        $this->giftCardTotal = $this->giftCardTotal()->subtract($short)->toFixed();
        // Taken off the cards last listed first, as each paid what the ones before it left.
        $left = $short;
        foreach (array_reverse(array_keys($this->giftCards)) as $index) {
            $amount = Money::of($this->giftCards[$index]['amount']);
            $off = $left->compare($amount) > 0 ? $amount : $left;
            $this->giftCards[$index]['amount'] = $amount->subtract($off)->toFixed();
            $left = $left->subtract($off);
        }
        if ($this->paidAmount !== null) {
            $this->paidAmount = $this->paidAmount()->subtract($short)->atLeastZero()->toFixed();
        }
        $this->needsAttention($reason, $at);

        return $short;
    }

    /** What the changes to the items' prices took off, in all: coupons and the shop's. */
    public function discountTotal(): Money
    {
        return self::sum($this->lines(), static fn(OrderLine $line): Money => $line->discount());
    }

    /**
     * The coupons and automatic discounts, each once with what it took off the whole order.
     *
     * @return list<OrderDiscount>
     */
    public function discounts(): array
    {
        $discounts = [];
        foreach ($this->allLines() as $line) {
            foreach ($line->adjustments() as $row) {
                $adjustment = $row->adjustment();
                if (!$adjustment->isDiscount()) {
                    continue;
                }
                $key = $adjustment->kind . '|' . $adjustment->source;
                $before = $discounts[$key] ?? null;
                $discounts[$key] = new OrderDiscount(
                    $adjustment->source,
                    $adjustment->label,
                    ($before->amount ?? Money::zero())->subtract($adjustment->amount),
                    ($before->freeShipping ?? false) || $adjustment->freeShipping,
                    $adjustment->kind === PriceAdjustment::AUTOMATIC,
                );
            }
        }

        return array_values($discounts);
    }

    /**
     * Every change to a line's price, with its line.
     *
     * @return list<OrderAdjustment>
     */
    public function adjustments(): array
    {
        return array_merge([], ...array_map(static fn(OrderLine $line): array => $line->adjustments(), $this->allLines()));
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

    public function fulfilledAt(): ?\DateTimeImmutable
    {
        return $this->fulfilledAt;
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
        return ($this->shippingLine()?->refundedQuantity() ?? 0) > 0;
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
            throw new OrderRuleViolation('Order #%d is not paid; only paid orders are refunded.', 'orillacart_order_not_refundable', values: [$this->id()]);
        }
        if ($refund->amount->isNegative() || $refund->amount->isZero()) {
            throw new OrderRuleViolation('A refund is for more than nothing.', 'orillacart_invalid_refund_amount');
        }
        if ($refund->amount->compare($this->refundable()) > 0) {
            throw new OrderRuleViolation('At most %1$s %2$s is left to refund.', 'orillacart_invalid_refund_amount', values: [$this->refundable(), $this->currency]);
        }
        $lines = [];
        foreach ($this->lines() as $line) {
            $lines[$line->position()] = $line;
        }
        foreach ($refund->quantities as $position => $quantity) {
            $line = $lines[$position] ?? throw new OrderRuleViolation('Order #%1$d has no line %2$d.', 'orillacart_invalid_refund_line', values: [$this->id(), $position + 1]);
            if ($quantity < 1 || $quantity > $line->refundableQuantity()) {
                throw new OrderRuleViolation('Line "%1$s" has %2$d items left to refund.', 'orillacart_invalid_refund_quantity', values: [$line->name(), $line->refundableQuantity()]);
            }
        }
        if ($refund->shipping && ($this->shippingLine() === null || $this->isShippingRefunded())) {
            throw new OrderRuleViolation('The shipping was refunded before, or the order ships nothing.', 'orillacart_invalid_refund_shipping');
        }
    }

    /**
     * Records money given back. Refunded items stop their downloads once none of the line is left; an order
     * refunded in full becomes Refunded, and the stock of what was not refunded item by item stays sold.
     *
     * @return RefundShare the tax given back, by rate, and the stock to put back: the refunded items', when the
     *                     refund restocks
     *
     * @throws OrderRuleViolation as checkRefund()
     */
    public function refund(OrderRefund $refund): RefundShare
    {
        $this->checkRefund($refund);
        $before = array_map(static fn(OrderTax $row): Money => $row->refunded(), $this->taxRows());
        $tax = $this->refundTax($refund);
        $taken = [];
        foreach ($this->lines() as $line) {
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
        if ($refund->shipping) {
            $this->shippingLine()?->refund(1, false);
        }
        $this->refundedTotal = $this->refundedTotal()->add($refund->amount)->toFixed();
        $this->refundedTax = $this->refundedTax()->add($tax)->toFixed();
        if ($this->refundable()->isZero()) {
            // The items not refunded one by one keep their stock: they may come back, and be restocked then.
            $this->status = OrderStatus::Refunded;
            $this->grants->clear();
        }

        return new RefundShare($tax, $this->givenBack($before), $taken);
    }

    /**
     * The tax inside a refund's amount, given back on the lines it covers: the refunded items (by quantity) and
     * shipping, or every line when it names neither, each line's rates by what they charged. The refund's tax
     * is the share the lines' tax is of what they cost; never more than is left of the order's tax, and the
     * refund that empties the order gives back all of it.
     */
    private function refundTax(OrderRefund $refund): Money
    {
        $covered = [];
        foreach ($this->lines() as $line) {
            $quantity = $refund->quantities[$line->position()] ?? 0;
            if ($quantity > 0) {
                $covered[] = [$line, ...$this->lineShare($line, $quantity)];
            }
        }
        $shipping = $this->shippingLine();
        if ($refund->shipping && $shipping !== null) {
            $covered[] = [$shipping, ...$this->lineShare($shipping, 1)];
        }
        if ($covered === []) {
            foreach ($this->allLines() as $line) {
                $covered[] = [$line, ...$this->lineShare($line, $line->quantity())];
            }
        }
        // In the currency's cents, line by line: a credit note and the taxes report show 0.83 of 5 on 24, not 0.8333.
        $decimals = new Currency($this->currency)->decimals();
        $gross = self::sum($covered, static fn(array $share): Money => $share[1]);
        $tax = self::sum($covered, static fn(array $share): Money => $share[2]);
        $left = $refund->amount;
        foreach ($covered as $index => [$line, $lineGross]) {
            $part = $index === \count($covered) - 1 ? $left : ($gross->isZero() ? Money::zero() : $refund->amount->proportion($lineGross, $gross)->rounded($decimals));
            $left = $left->subtract($part);
            $line->refunded($part);
        }

        $taxLeft = $this->tax()->subtract($this->refundedTax())->atLeastZero();
        if ($refund->amount->compare($this->refundable()) >= 0) {
            foreach ($this->taxRows() as $row) {
                $row->giveBack($row->amount()->subtract($row->refunded())->atLeastZero());
            }

            return $taxLeft;
        }
        $share = $gross->isZero() ? Money::zero() : $tax->proportion($refund->amount, $gross)->rounded($decimals);
        $share = $share->compare($taxLeft) > 0 ? $taxLeft : $share;
        $left = $share;
        foreach ($covered as $index => [$line, , $lineTax]) {
            $part = $index === \count($covered) - 1 ? $left : ($tax->isZero() ? Money::zero() : $share->proportion($lineTax, $tax)->rounded($decimals));
            $left = $left->subtract($part);
            $line->refundTax($part);
        }

        return $share;
    }

    /**
     * What the customer paid for some of a line's items, and the tax inside it.
     *
     * @return array{Money, Money}
     */
    private function lineShare(OrderLine $line, int $quantity): array
    {
        $lineTax = $line->tax();
        $lineGross = $line->net()->add($this->pricesIncludeTax ? Money::zero() : $lineTax);
        $part = Money::of((string) $quantity);
        $whole = Money::of((string) $line->quantity());

        return [$lineGross->proportion($part, $whole), $lineTax->proportion($part, $whole)];
    }

    /**
     * What a refund gave back of each rate: the order's tax rows now, less what they had given back before.
     *
     * @param list<Money> $before each row's refunded amount before the refund, as taxRows() lists them
     *
     * @return list<TaxLine>
     */
    private function givenBack(array $before): array
    {
        $shares = [];
        foreach ($this->taxRows() as $index => $row) {
            $given = $row->refunded()->subtract($before[$index] ?? Money::zero());
            if ($given->isZero()) {
                continue;
            }
            // By whom it is owed: a county's and a city's "Local tax" at 1 % each are two.
            $key = $row->jurisdiction()->code . '|' . $row->percent();
            $shares[$key] = new TaxLine($row->name(), $row->percent(), ($shares[$key]->amount ?? Money::zero())->add($given), $row->jurisdiction()->code);
        }

        return array_values($shares);
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
            throw new OrderRuleViolation('Order #%d is not refunded; its items go back in stock with a refund.', 'orillacart_order_not_refunded', values: [$this->id()]);
        }
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[$line->position()] = $line;
        }
        $taken = [];
        foreach ($quantities as $position => $quantity) {
            $line = $lines[$position] ?? throw new OrderRuleViolation('Order #%1$d has no line %2$d.', 'orillacart_invalid_refund_line', values: [$this->id(), $position + 1]);
            if ($quantity < 1 || $quantity > $line->restockable()) {
                throw new OrderRuleViolation('Line "%1$s" has %2$d items to put back in stock.', 'orillacart_invalid_refund_quantity', values: [$line->name(), $line->restockable()]);
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
        // The corrected answer reads the same for the shop.
        foreach ($answers as $id => $answer) {
            if (isset($this->shopWords['fields'][$id])) {
                $this->shopWords['fields'][$id]['answer'] = trim($answer);
            }
        }
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
    /** Whether the address is another place than where the order ships now. */
    public function shipsElsewhere(?Address $shippingAddress): bool
    {
        return $shippingAddress !== null && !$shippingAddress->samePlaceAs($this->shippingAddress);
    }

    /**
     * @return bool whether it now ships somewhere else
     *
     * @throws OrderRuleViolation for a shipped order sent somewhere else: its parcel left; the email and phones stay
     *                            the customer's to change
     */
    public function changeContact(string $email, Address $billing, ?Address $shippingAddress): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 200) {
            throw new OrderRuleViolation('An order needs a valid email address.', 'orillacart_invalid_email');
        }
        if (($shippingAddress === null) !== ($this->shippingName === null)) {
            throw new OrderRuleViolation($this->shippingName === null ? 'This order ships nothing, so it has no shipping address.' : 'This order ships, so it needs a shipping address.', 'orillacart_invalid_address');
        }
        $readdressed = $this->shipsElsewhere($shippingAddress);
        if ($readdressed && \in_array($this->status, [OrderStatus::Shipped, OrderStatus::Completed], true)) {
            throw new OrderRuleViolation('The order shipped already: its address cannot change. The email and phone still can.', 'orillacart_order_shipped');
        }
        // A courier's office or locker chosen near the old address, and the price and tax for there, would stay.
        $postcode = static fn(Address $address): string => strtoupper((string) preg_replace('/[\s-]+/', '', $address->postcode()));
        if ($shippingAddress !== null && $this->pickupPointId !== null && !str_starts_with($this->shippingOption ?? '', 'pickup:')
            && ($shippingAddress->country() !== $this->shippingAddress->country() || $postcode($shippingAddress) !== $postcode($this->shippingAddress))) {
            throw new OrderRuleViolation('The order goes to an office or locker near its old address. Change how it ships to a service to the door first, then the address, then choose an office near the new one.', 'orillacart_pickup_point_stale');
        }
        $this->email = $email;
        $this->billing = $billing;
        $this->shippingAddress = $shippingAddress ?? Address::none();

        return $readdressed;
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
     * @param list<LineDraft>                                  $lines        the items, the shipping and the fees, priced again
     * @param array{reason: string, certificate: string}|null $taxExemption the exemption the new prices went by
     * @param string|null                                      $taxProvider  the tax service that worked them out
     *
     * @throws OrderRuleViolation for an order not on hold, one without items, or one gift cards paid part of
     */
    public function replaceItems(array $lines, ?ShippingChoice $shipping, ?Address $shippingAddress, Money $total, bool $pricesIncludeTax, ?array $taxExemption, ?string $taxProvider): void
    {
        $this->requireOnHold();
        if (array_filter($lines, static fn(LineDraft $line): bool => $line->type->isItem()) === []) {
            throw new OrderRuleViolation('An order has at least one item.', 'orillacart_empty_order');
        }
        if ($this->giftCards !== []) {
            throw new OrderRuleViolation('Gift cards paid part of order #%d: its items stay as they are; cancel it and place another to change them.', 'orillacart_order_gift_cards', values: [$this->id()]);
        }
        if (!self::addsUp($lines, $total, $pricesIncludeTax)) {
            throw new \LogicException('The order total is not the sum of its parts.');
        }
        if (($shipping === null) !== ($shippingAddress === null)
            || (array_filter($lines, static fn(LineDraft $line): bool => $line->type === LineType::Shipping) === []) !== ($shipping === null)) {
            throw new \LogicException('An order that ships has a shipping address, a way to ship and a shipping line; one that does not has none.');
        }
        $this->lines->clear();
        foreach (self::ordered($lines) as $position => $line) {
            $this->lines->add(OrderLine::of($this, $line, $position));
        }
        $this->shippingAddress = $shippingAddress ?? Address::none();
        $this->shippingOption = $shipping?->option;
        $this->shippingName = $shipping === null ? null : mb_substr("{$shipping->carrier} – {$shipping->rate}", 0, 200);
        $this->pickupPointId = $shipping?->pickupPointId;
        $this->pickupPoint = $shipping?->pickupPoint === null ? null : mb_substr($shipping->pickupPoint, 0, 255);
        $this->shipsInShopWords($shipping);
        $this->total = $total->toFixed();
        $this->pricesIncludeTax = $pricesIncludeTax;
        $this->taxExemption = $taxExemption;
        if ($taxProvider !== $this->taxProvider) {
            // Another service, or none: what the one before recorded is not this order's any more.
            $this->taxReference = null;
        }
        $this->taxProvider = $taxProvider === null ? null : mb_substr($taxProvider, 0, 40);
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
        $this->requireOpen(new Text('put on hold'));
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
            throw new OrderRuleViolation('Order #%1$d is %2$s and cannot be marked paid.', 'orillacart_order_closed', values: [$this->id(), $this->status->phrase()]);
        }
        if ($this->status->isOpen()) {
            $this->status = OrderStatus::Processing;
        }
        $this->transactionId = $transactionId;
        $this->paidAt = $at;
        // What a provider took, and what gift cards paid besides.
        $this->paidAmount = ($amount === null ? $this->total() : $amount->add($this->giftCardTotal()))->toFixed();

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
            throw new OrderRuleViolation('Order #%1$d is %2$s and cannot become %3$s.', 'orillacart_invalid_status_change', values: [$this->id(), $this->status->phrase(), $status->phrase()]);
        }

        switch ($status) {
            case OrderStatus::OnHold:
                $this->hold();

                return [];
            case OrderStatus::Completed:
                // Done: the courier handed over the cash of an order paid on delivery, or the shop says the money
                // came, if nothing came before. Recorded as paid in full, so it can be refunded.
                $this->status = OrderStatus::Completed;
                if ($this->paidAt === null) {
                    $this->paidAt = $at;
                    $this->paidAmount = $this->total()->toFixed();
                }
                $this->fulfilledAt ??= $at;

                return [];
            case OrderStatus::Cancelled:
                return $this->close(OrderStatus::Cancelled);
            default:
                $this->status = $status;
                if ($status === OrderStatus::Shipped) {
                    $this->fulfilledAt ??= $at;
                }

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
    public function awaitPayment(string $gateway, string $name, string $note, \DateTimeImmutable $at, \Closure $take, ?string $shopName = null): void
    {
        if (!$this->isPayable()) {
            throw new OrderRuleViolation('Order #%1$d is %2$s and cannot be paid now.', 'orillacart_order_not_payable', values: [$this->id(), $this->status->phrase()]);
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
        $this->shopWord('payment_name', $shopName === null || $shopName === $this->paymentName ? null : mb_substr($shopName, 0, 100));
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
            throw new OrderRuleViolation('Order #%1$d is %2$s and cannot be opened again.', 'orillacart_order_not_reopenable', values: [$this->id(), $this->status->phrase()]);
        }
        $lines = array_values($this->lines->toArray());
        $taken = $take(array_map(static fn(OrderLine $line): array => $line->stockReleased(), $lines));
        foreach ($lines as $index => $line) {
            $line->retake($taken[$index] ?? []);
        }
        $this->status = OrderStatus::Processing;
        $this->transactionId = $transactionId;
        $this->paidAt = $at;
        // What a provider took, and what gift cards paid besides.
        $this->paidAmount = ($amount === null ? $this->total() : $amount->add($this->giftCardTotal()))->toFixed();
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
        // The notes go before the details do: "sent to ada@example.com" keeps nobody's email.
        $personal = [$this->email];
        foreach ([$this->billing, $this->shippingAddress] as $address) {
            array_push($personal, trim($address->firstName() . ' ' . $address->lastName()), $address->lastName(), $address->phone(), $address->street());
        }
        $personal = array_values(array_unique(array_filter($personal, static fn(string $part): bool => mb_strlen(trim($part)) >= 3)));
        usort($personal, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($this->notes as $note) {
            $note->redact($personal);
        }
        $this->email = 'anonymised@invalid';
        $this->billing = $this->billing->anonymised();
        $this->shippingAddress = $this->shippingAddress->isEmpty() ? $this->shippingAddress : $this->shippingAddress->anonymised();
        $this->customerId = null;
        $this->note = '';
        $this->fields = [];
        foreach ($this->lines as $line) {
            $line->forgetPersonalisation();
        }
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

    /**
     * The items first, then the shipping, then the fees: items keep the places refunds and downloads name them by.
     *
     * @param list<LineDraft> $lines
     *
     * @return list<LineDraft>
     */
    private static function ordered(array $lines): array
    {
        $ordered = $lines;
        // Stable: lines of a rank keep their order.
        usort($ordered, static fn(LineDraft $a, LineDraft $b): int => $a->type->rank() <=> $b->type->rank());

        return $ordered;
    }

    /**
     * @template T
     *
     * @param list<T>          $of
     * @param \Closure(T): Money $amount
     */
    private static function sum(array $of, \Closure $amount): Money
    {
        return array_reduce($of, static fn(Money $sum, mixed $one): Money => $sum->add($amount($one)), Money::zero());
    }

    /**
     * The total is what the lines cost with their adjustments, and plus their tax unless the amounts include it.
     *
     * @param list<LineDraft> $lines
     */
    private static function addsUp(array $lines, Money $total, bool $includesTax): bool
    {
        $sum = Money::zero();
        foreach ($lines as $line) {
            $sum = $sum->add($line->total);
            foreach ($line->adjustments as $adjustment) {
                $sum = $sum->add($adjustment->amount);
            }
            if (!$includesTax) {
                foreach ($line->taxes as $tax) {
                    $sum = $sum->add($tax->amount);
                }
            }
        }

        return $sum->equals($total);
    }

    private function requireOnHold(): void
    {
        if ($this->status !== OrderStatus::OnHold) {
            throw new OrderRuleViolation('Only orders on hold can change their items; order #%1$d is %2$s.', 'orillacart_order_not_editable', values: [$this->id(), $this->status->phrase()]);
        }
    }

    private function requireOpen(Text $change): void
    {
        if (!$this->status->isOpen()) {
            throw new OrderRuleViolation('Order #%1$d is %2$s and cannot be %3$s.', 'orillacart_order_closed', values: [$this->id(), $this->status->phrase(), $change]);
        }
    }
}
