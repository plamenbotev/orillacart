<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * One item of an order, kept as it was sold: name, options and prices do not follow later catalog changes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_lines')]
class OrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $productId;

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

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $tax;

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

    /** What coupons took off the total; the tax is on what is left. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $discount = '0';

    /** @var list<array{name: string, quantity: int}> what one bundle held, for a bundle */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contents = null;

    /** @var list<int>|null for a bundle, the variations chosen and the optional products added */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bundleChoices = null;

    /** How many of the items were refunded. */
    #[ORM\Column(options: ['default' => 0])]
    private int $refundedQuantity = 0;

    /** The money given back for this line, with its tax: its share of each refund that covered it. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refundedAmount = '0';

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
        $this->productId = $draft->productId;
        $this->variationId = $draft->variationId;
        $this->optionIds = $draft->optionIds;
        $this->options = $draft->options;
        $this->name = mb_substr($draft->name, 0, 255);
        $this->sku = $draft->sku;
        $this->quantity = $draft->quantity;
        $this->unitPrice = $draft->unitPrice->toFixed();
        $this->total = $draft->total->toFixed();
        $this->tax = $draft->tax->toFixed();
        $this->ships = $draft->ships;
        $this->downloads = array_map(static fn(LineDownload $file): array => ['file_id' => $file->fileId, 'name' => $file->name, 'storage_key' => $file->storageKey, 'mime_type' => $file->mimeType], $draft->downloads);
        $this->downloadLimit = $draft->downloadLimit;
        $this->downloadExpiryDays = $draft->downloadExpiryDays;
        if ($draft->backordered < 0 || $draft->backordered > $draft->quantity) {
            throw new OrderRuleViolation('A line cannot owe more than its quantity.', 'orillacart_invalid_backorder');
        }
        $this->backordered = $draft->backordered;
        $this->backorderShown = $draft->backorderShown;
        $discount = $draft->discount ?? Money::zero();
        if ($discount->isNegative() || $discount->compare($draft->total) > 0) {
            throw new OrderRuleViolation('A discount takes off no more than the line costs.', 'orillacart_invalid_discount');
        }
        $this->discount = $discount->toFixed();
        $this->contents = $draft->contents === [] ? null : $draft->contents;
        $this->bundleChoices = $draft->bundleChoices === [] ? null : $draft->bundleChoices;
        $this->stockTaken = array_map(static fn(TakenItem $taken): array => ['item' => $taken->item, 'stockroom_id' => $taken->stockroomId, 'quantity' => $taken->quantity], $draft->taken);
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function productId(): int
    {
        return $this->productId;
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

    public function discount(): Money
    {
        return Money::of($this->discount);
    }

    public function tax(): Money
    {
        return Money::of($this->tax);
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
            throw new OrderRuleViolation(\sprintf('Line "%s" has %d items left to refund.', $this->name, $this->refundableQuantity()), 'orillacart_invalid_refund_quantity');
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
