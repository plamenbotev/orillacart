<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One line of an order, kept as it was sold: an item (name, options and prices do not follow later catalog
 * changes), the shipping, or a fee. Each keeps the taxes charged on it, per rate and jurisdiction, and the changes
 * made to its price after its unit price. What a line is decides what it does: each type is its own class
 * (LineType), made by of().
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_lines')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 20)]
#[ORM\DiscriminatorMap(['item' => ItemLine::class, 'given' => GivenItemLine::class, 'add_on' => AddOnItemLine::class, 'charge' => ChargeLine::class, 'shipping' => ShippingLine::class, 'fee' => FeeLine::class])]
abstract class OrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null for the shipping and fees. */
    #[ORM\Column(nullable: true)]
    private ?int $productId;

    #[ORM\Column(nullable: true)]
    private ?int $variationId;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $optionIds;

    /** @var list<array{attribute: string, option: string}> */
    #[ORM\Column(type: 'json')]
    private array $options;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sku;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $unitPrice;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $total;

    #[ORM\Column]
    private bool $ships;

    /** @var list<array{item: string, stockroom_id: int, quantity: int}> */
    #[ORM\Column(type: 'json')]
    private array $stockTaken;

    /**
     * @var array<string, int>|null what the line gave back, by item, when its order closed unpaid: taken again
     *                              if the money comes after all
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stockReleased = null;

    /** @var list<array{file_id: int, name: string, storage_key: string, mime_type: string}> */
    #[ORM\Column(type: 'json')]
    private array $downloads;

    #[ORM\Column(nullable: true)]
    private ?int $downloadLimit;

    #[ORM\Column(nullable: true)]
    private ?int $downloadExpiryDays;

    #[ORM\Column(options: ['default' => 0])]
    private int $backordered = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $backorderShown = false;

    /** @var Collection<int, OrderTax> */
    #[ORM\OneToMany(targetEntity: OrderTax::class, mappedBy: 'line', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $taxes;

    /** @var Collection<int, OrderAdjustment> */
    #[ORM\OneToMany(targetEntity: OrderAdjustment::class, mappedBy: 'line', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $adjustments;

    /** @var list<array{name: string, quantity: int}> what one bundle held, for a bundle */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contents = null;

    /** @var list<int>|null for a bundle, the variations chosen and the optional products added */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bundleChoices = null;

    /** @var list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>|null the answers to the product's forms, as sold */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $personalisation = null;

    /** How many of the items were refunded. */
    /**
     * Its words in the shop's own language where the shopper read others (design 51): what the shop reads.
     *
     * @var array{name?: string, options?: list<array{attribute: string, option: string}>, contents?: list<array{name: string, quantity: int}>, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $shopWords = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $refundedQuantity = 0;

    /** The money given back for this line, with its tax: its share of each refund that covered it. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedAmount = '0';

    /** The line of the draft's type. */
    public static function of(Order $order, LineDraft $draft, int $position): self
    {
        return match ($draft->type) {
            LineType::Item => new ItemLine($order, $draft, $position),
            LineType::Given => new GivenItemLine($order, $draft, $position),
            LineType::AddOn => new AddOnItemLine($order, $draft, $position),
            LineType::Charge => new ChargeLine($order, $draft, $position),
            LineType::Shipping => new ShippingLine($order, $draft, $position),
            LineType::Fee => new FeeLine($order, $draft, $position),
        };
    }

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
        private Order $order,
        LineDraft $draft,
        #[ORM\Column]
        private int $position,
    ) {
        if ($draft->quantity < 1) {
            throw new OrderRuleViolation('An order line has at least one item.', 'orillacart_invalid_quantity');
        }
        if ($draft->type !== $this->type()) {
            throw new \LogicException('A line is of its draft\'s type.');
        }
        $this->check($draft);
        $this->productId = $draft->productId;
        $this->variationId = $draft->variationId;
        $this->optionIds = $draft->optionIds;
        $this->options = $draft->options;
        $this->name = mb_substr($draft->name, 0, 255);
        $this->sku = $draft->sku;
        $this->quantity = $draft->quantity;
        $this->unitPrice = $draft->unitPrice->toFixed();
        $this->total = $draft->total->toFixed();
        $this->ships = $draft->ships;
        $this->downloads = array_map(static fn(LineDownload $file): array => ['file_id' => $file->fileId, 'name' => $file->name, 'storage_key' => $file->storageKey, 'mime_type' => $file->mimeType], $draft->downloads);
        $this->downloadLimit = $draft->downloadLimit;
        $this->downloadExpiryDays = $draft->downloadExpiryDays;
        if ($draft->backordered < 0 || $draft->backordered > $draft->quantity) {
            throw new OrderRuleViolation('A line cannot owe more than its quantity.', 'orillacart_invalid_backorder');
        }
        $this->backordered = $draft->backordered;
        $this->backorderShown = $draft->backorderShown;
        $this->taxes = new ArrayCollection();
        $this->adjustments = new ArrayCollection();
        foreach ($draft->taxes as $tax) {
            $this->taxes->add(new OrderTax($this, $tax));
        }
        foreach ($draft->adjustments as $adjustment) {
            $this->adjustments->add(new OrderAdjustment($this, $adjustment));
        }
        if ($this->net()->isNegative()) {
            throw new OrderRuleViolation('Changes to a price take off no more than the line costs.', 'orillacart_invalid_discount');
        }
        $this->contents = $draft->contents === [] ? null : $draft->contents;
        $this->bundleChoices = $draft->bundleChoices === [] ? null : $draft->bundleChoices;
        $this->personalisation = $draft->personalisation === [] ? null : $draft->personalisation;
        $this->shopWords = $draft->shopWords === [] ? null : $draft->shopWords;
        if (isset($this->shopWords['name'])) {
            $this->shopWords['name'] = mb_substr($this->shopWords['name'], 0, 255);
        }
        $this->stockTaken = array_map(static fn(TakenItem $taken): array => ['item' => $taken->item, 'stockroom_id' => $taken->stockroomId, 'quantity' => $taken->quantity], $draft->taken);
    }

    /** @return list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> */
    public function personalisation(): array
    {
        return $this->personalisation ?? [];
    }

    /**
     * The files the answers sent.
     *
     * @return list<int>
     */
    public function uploadIds(): array
    {
        $ids = [];
        foreach ($this->personalisation() as $answer) {
            if ($answer['upload_id'] !== null) {
                $ids[] = $answer['upload_id'];
            }
        }

        return $ids;
    }

    /** The answers may name people: anonymising keeps the questions and what they cost, not the answers. */
    public function forgetPersonalisation(): void
    {
        if ($this->personalisation !== null) {
            $this->personalisation = array_map(static fn(array $answer): array => ['answer' => '', 'value' => '', 'upload_id' => null] + $answer, $this->personalisation);
        }
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function position(): int
    {
        return $this->position;
    }

    abstract public function type(): LineType;

    /** Whether the draft makes a line of this type; throws when it does not. */
    abstract protected function check(LineDraft $draft): void;

    /** A product the order holds: sold, or given with it. */
    public function isItem(): bool
    {
        return false;
    }

    /** The discount that gave the item; null for one sold, and for the shipping and fees. */
    public function givenBy(): ?GivenBy
    {
        return null;
    }

    /** The add-on's line type that kept the item in the cart; null for any other line. */
    public function addOn(): ?KeptBy
    {
        return null;
    }

    /** The product sold; 0 for the shipping and fees. */
    public function productId(): int
    {
        return $this->productId ?? 0;
    }

    public function variationId(): ?int
    {
        return $this->variationId;
    }

    /** @return list<int> */
    public function optionIds(): array
    {
        return $this->optionIds;
    }

    /** @return list<array{attribute: string, option: string}> */
    public function options(): array
    {
        return $this->options;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** As the shop reads it: in its own language, where the shopper read another. */
    public function shopName(): string
    {
        return $this->shopWords['name'] ?? $this->name;
    }

    /** @return list<array{attribute: string, option: string}> */
    public function shopOptions(): array
    {
        return $this->shopWords['options'] ?? $this->options();
    }

    /** @return list<array{name: string, quantity: int}> */
    public function shopContents(): array
    {
        return $this->shopWords['contents'] ?? $this->contents();
    }

    /** @return list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> */
    public function shopPersonalisation(): array
    {
        return $this->shopWords['personalisation'] ?? $this->personalisation();
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return Money::of($this->unitPrice);
    }

    public function total(): Money
    {
        return Money::of($this->total);
    }

    /** @return list<array{name: string, quantity: int}> what one bundle held; empty for a product of its own */
    public function contents(): array
    {
        return $this->contents ?? [];
    }

    /** @return list<int> */
    public function bundleChoices(): array
    {
        return $this->bundleChoices ?? [];
    }

    /** What the changes to its price took off, in all: coupons and the shop's, less any that added to it. */
    public function discount(): Money
    {
        return Money::zero()->subtract($this->adjustment());
    }

    /** What the changes to its price came to: below zero when they lowered it. */
    public function adjustment(): Money
    {
        return array_reduce($this->adjustments->toArray(), static fn(Money $sum, OrderAdjustment $adjustment): Money => $sum->add($adjustment->amount()), Money::zero());
    }

    /** What the line costs before tax, or with prices that include tax, as charged: its total and adjustments. */
    public function net(): Money
    {
        return $this->total()->add($this->adjustment());
    }

    /** @return list<OrderAdjustment> */
    public function adjustments(): array
    {
        return array_values($this->adjustments->toArray());
    }

    /** @return list<OrderTax> */
    public function taxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    public function tax(): Money
    {
        return array_reduce($this->taxes->toArray(), static fn(Money $sum, OrderTax $tax): Money => $sum->add($tax->amount()), Money::zero());
    }

    /** @internal for Order: the line's share of a refund's tax, spread over its rates by what each charged. */
    public function refundTax(Money $tax): void
    {
        $whole = $this->tax();
        $taxes = array_values(array_filter($this->taxes(), static fn(OrderTax $row): bool => !$row->amount()->isZero()));
        if ($tax->isZero() || $whole->isZero() || $taxes === []) {
            return;
        }
        $left = $tax;
        foreach ($taxes as $index => $row) {
            $share = $index === \count($taxes) - 1 ? $left : $tax->proportion($row->amount(), $whole);
            $left = $left->subtract($share);
            $row->giveBack($share);
        }
    }

    /** How many of the quantity the shop owes: they were not in stock when the order was placed. */
    public function backordered(): int
    {
        return $this->backordered;
    }

    /** Whether the customer was told of the backorder. */
    public function isBackorderShown(): bool
    {
        return $this->backorderShown;
    }

    public function ships(): bool
    {
        return $this->ships;
    }

    /** @return list<TakenItem> */
    public function stockTaken(): array
    {
        return array_map(static fn(array $taken): TakenItem => new TakenItem($taken['item'], $taken['stockroom_id'], $taken['quantity']), $this->stockTaken);
    }

    /** @return list<LineDownload> */
    public function downloads(): array
    {
        return array_map(static fn(array $file): LineDownload => new LineDownload($file['file_id'], $file['name'], $file['storage_key'], $file['mime_type']), $this->downloads);
    }

    public function downloadLimit(): ?int
    {
        return $this->downloadLimit;
    }

    public function downloadExpiryDays(): ?int
    {
        return $this->downloadExpiryDays;
    }

    /** @internal for Order: the stock went back */
    public function forgetStock(): void
    {
        $this->stockTaken = [];
    }

    /**
     * The order closed unpaid: the stock goes back, and the line remembers how much of each item it held.
     *
     * @return list<TakenItem>
     */
    public function releaseStock(): array
    {
        $taken = $this->stockTaken();
        $released = [];
        foreach ($taken as $part) {
            $released[$part->item] = ($released[$part->item] ?? 0) + $part->quantity;
        }
        $this->stockReleased = $released;
        $this->stockTaken = [];

        return $taken;
    }

    /** @return array<string, int> what the line gave back when its order closed unpaid, by item */
    public function stockReleased(): array
    {
        return $this->stockReleased ?? [];
    }

    /**
     * The stock given back is taken again, as the order opens again.
     *
     * @param list<TakenItem> $taken
     */
    public function retake(array $taken): void
    {
        $this->stockTaken = array_map(static fn(TakenItem $part): array => ['item' => $part->item, 'stockroom_id' => $part->stockroomId, 'quantity' => $part->quantity], $taken);
        $this->stockReleased = null;
    }

    public function refundedQuantity(): int
    {
        return $this->refundedQuantity;
    }

    public function refundedAmount(): Money
    {
        return Money::of($this->refundedAmount);
    }

    /** @internal for Order: this line's share of a refund. */
    public function refunded(Money $amount): void
    {
        $this->refundedAmount = $this->refundedAmount()->add($amount)->toFixed();
    }

    /** Items not refunded yet that hold stock, so they can go back in stock. */
    public function restockable(): int
    {
        return $this->stockTaken === [] ? 0 : $this->refundableQuantity();
    }

    /** Items not refunded yet. */
    public function refundableQuantity(): int
    {
        return $this->quantity - $this->refundedQuantity;
    }

    /**
     * @internal for Order: some of the items were refunded. Owed items are refunded first, as they never left;
     * the stock the rest took is given up, in the share each item took, from the last stockroom first.
     *
     * @return list<TakenItem> the stock to put back, when $restock
     */
    public function refund(int $quantity, bool $restock): array
    {
        if ($quantity < 1 || $quantity > $this->refundableQuantity()) {
            throw new OrderRuleViolation('Line "%1$s" has %2$d items left to refund.', 'orillacart_invalid_refund_quantity', values: [$this->name, $this->refundableQuantity()]);
        }
        $remaining = $this->refundableQuantity();
        $this->refundedQuantity += $quantity;
        $this->backordered = max(0, $this->backordered - $quantity);

        // Each item's stock was taken for the units not refunded yet: give up its share for the refunded ones.
        $byItem = [];
        foreach ($this->stockTaken as $index => $taken) {
            $byItem[$taken['item']][] = $index;
        }
        $kept = $this->stockTaken;
        $returned = [];
        foreach ($byItem as $item => $indexes) {
            $total = array_sum(array_map(static fn(int $index): int => $kept[$index]['quantity'], $indexes));
            $give = intdiv($total * $quantity, $remaining);
            foreach (array_reverse($indexes) as $index) {
                $part = min($give, $kept[$index]['quantity']);
                if ($part <= 0) {
                    continue;
                }
                $give -= $part;
                $kept[$index] = ['item' => $kept[$index]['item'], 'stockroom_id' => $kept[$index]['stockroom_id'], 'quantity' => $kept[$index]['quantity'] - $part];
                if ($restock) {
                    $returned[] = new TakenItem((string) $item, $kept[$index]['stockroom_id'], $part);
                }
            }
        }
        $this->stockTaken = array_values(array_filter($kept, static fn(array $taken): bool => $taken['quantity'] > 0));

        return $returned;
    }
}
