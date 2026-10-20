<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * A shipment of an order: some or all of its items, in one or more parcels. Either the shop booked it with a
 * courier, through the carrier's shipping method (its number, the link the customer follows it by, and where the
 * courier last said it was), or the shop sent it itself and entered each parcel's tracking number.
 */
#[ORM\Entity]
#[ORM\Table(name: 'consignments')]
#[ORM\Index(name: 'consignment_order', columns: ['order_id'])]
#[ORM\Index(name: 'consignment_status', columns: ['status'])]
class Consignment
{
    public const int MAX_PARCELS = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ConsignmentStatus::class)]
    private ConsignmentStatus $status = ConsignmentStatus::Booked;

    /** The courier's last words about it: "At the Sofia depot". */
    #[ORM\Column(length: 255)]
    private string $tracking = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var list<array{line: int, quantity: int}> the order's lines it carries, by position */
    #[ORM\Column(type: 'json')]
    private array $items;

    /** @var list<array{number: string, tracking_url: ?string}> each parcel's number, when there is one each */
    #[ORM\Column(type: 'json')]
    private array $parcels;

    /**
     * @param int|null              $carrierId null for a shipment the shop sent itself
     * @param string|null           $method    the shipping method that booked it; null for one the shop sent itself
     * @param array<int, int>       $items     quantities by line position
     * @param list<ParcelTracking>  $parcels
     */
    private function __construct(
        #[ORM\Column]
        #[References('orders', onDelete: 'CASCADE')]
        private int $orderId,
        #[ORM\Column(nullable: true)]
        private ?int $carrierId,
        #[ORM\Column(length: 100)]
        private string $carrier,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $method,
        #[ORM\Column(length: 64)]
        private string $number,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $trackingUrl,
        #[ORM\Column]
        private \DateTimeImmutable $bookedAt,
        array $items,
        array $parcels,
    ) {
        $this->updatedAt = $bookedAt;
        $this->trackingUrl = self::link($trackingUrl);
        $this->items = [];
        foreach ($items as $line => $quantity) {
            if ($quantity < 1) {
                throw new ShippingRuleViolation('A shipment carries at least one of each item it lists.', 'orillacart_invalid_shipment_items');
            }
            $this->items[] = ['line' => $line, 'quantity' => $quantity];
        }
        if ($this->items === []) {
            throw new ShippingRuleViolation('A shipment carries at least one item.', 'orillacart_invalid_shipment_items');
        }
        if (\count($parcels) > self::MAX_PARCELS) {
            throw new ShippingRuleViolation(\sprintf('A shipment has up to %d parcels.', self::MAX_PARCELS), 'orillacart_invalid_shipment_parcels');
        }
        $this->parcels = array_map(static fn(ParcelTracking $parcel): array => ['number' => $parcel->number, 'tracking_url' => self::link($parcel->trackingUrl)], $parcels);
    }

    /**
     * A shipment a courier booked through the carrier's shipping method; the courier says where it is from there.
     *
     * @param array<int, int>      $items   quantities by line position
     * @param list<ParcelTracking> $parcels each parcel's own number, when the courier gives them
     */
    public static function booked(int $orderId, int $carrierId, string $carrier, string $method, string $number, ?string $trackingUrl, \DateTimeImmutable $at, array $items, array $parcels = []): self
    {
        return new self($orderId, $carrierId, mb_substr($carrier, 0, 100), $method, $number, $trackingUrl, $at, $items, $parcels);
    }

    /**
     * A shipment the shop sent itself, with each parcel's tracking number: on its way, until the shop says it
     * arrived.
     *
     * @param array<int, int>      $items   quantities by line position
     * @param list<ParcelTracking> $parcels at least one
     */
    public static function sent(int $orderId, string $carrier, array $parcels, \DateTimeImmutable $at, array $items): self
    {
        $carrier = trim($carrier);
        if ($carrier === '' || mb_strlen($carrier) > 100) {
            throw new ShippingRuleViolation('Name who carries the shipment, in up to 100 characters.', 'orillacart_invalid_shipment_carrier');
        }
        $first = $parcels[0] ?? throw new ShippingRuleViolation('A shipment has at least one parcel with its tracking number.', 'orillacart_invalid_shipment_parcels');
        $shipment = new self($orderId, null, $carrier, null, $first->number, $first->trackingUrl, $at, $items, $parcels);
        $shipment->status = ConsignmentStatus::OnTheWay;

        return $shipment;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The consignment has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    /** Null for a shipment the shop sent itself. */
    public function carrierId(): ?int
    {
        return $this->carrierId;
    }

    /** The carrier's name when it was booked, or who the shop sent it with. */
    public function carrier(): string
    {
        return $this->carrier;
    }

    /** The shipping method that booked it; null for a shipment the shop sent itself. */
    public function method(): ?string
    {
        return $this->method;
    }

    /** Whether the shop sent it itself, rather than booking it with a courier through a shipping method. */
    public function isSentByShop(): bool
    {
        return $this->method === null;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function trackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    /** @return array<int, int> quantities by line position */
    public function items(): array
    {
        return array_column($this->items, 'quantity', 'line');
    }

    /** @return list<ParcelTracking> */
    public function parcels(): array
    {
        return array_map(static fn(array $parcel): ParcelTracking => new ParcelTracking($parcel['number'], $parcel['tracking_url']), $this->parcels);
    }

    public function status(): ConsignmentStatus
    {
        return $this->status;
    }

    /** Whether its items are with it: not cancelled, nor coming back. */
    public function carries(): bool
    {
        return $this->status !== ConsignmentStatus::Cancelled && $this->status !== ConsignmentStatus::Returned;
    }

    public function tracking(): string
    {
        return $this->tracking;
    }

    public function bookedAt(): \DateTimeImmutable
    {
        return $this->bookedAt;
    }

    /**
     * @throws ShippingRuleViolation when the courier has it already
     */
    public function cancel(\DateTimeImmutable $at): void
    {
        if ($this->status !== ConsignmentStatus::Booked) {
            throw new ShippingRuleViolation(\sprintf('Shipment %s is %s; only a booked one is cancelled.', $this->number, str_replace('_', ' ', $this->status->value)), 'orillacart_shipment_not_cancellable');
        }
        $this->status = ConsignmentStatus::Cancelled;
        $this->updatedAt = $at;
    }

    /**
     * What the courier says now; whether it changed anything.
     */
    public function track(ConsignmentStatus $status, string $description, \DateTimeImmutable $at): bool
    {
        $description = mb_substr(trim($description), 0, 255);
        if (!$this->status->isOpen() || ($status === $this->status && $description === $this->tracking)) {
            return false;
        }
        $this->status = $status;
        $this->tracking = $description;
        $this->updatedAt = $at;

        return true;
    }

    /**
     * The shop says a shipment it sent itself arrived, or came back.
     *
     * @throws ShippingRuleViolation for a shipment a courier tracks, or one that arrived already
     */
    public function arrived(ConsignmentStatus $status, \DateTimeImmutable $at): void
    {
        if (!$this->isSentByShop() || $this->status !== ConsignmentStatus::OnTheWay || !\in_array($status, [ConsignmentStatus::Delivered, ConsignmentStatus::Returned], true)) {
            throw new ShippingRuleViolation(\sprintf('Shipment %s is followed by its courier, or arrived already.', $this->number), 'orillacart_shipment_not_followed_by_shop');
        }
        $this->status = $status;
        $this->updatedAt = $at;
    }

    private static function link(?string $url): ?string
    {
        return $url !== null && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) ? mb_substr($url, 0, 500) : null;
    }
}
