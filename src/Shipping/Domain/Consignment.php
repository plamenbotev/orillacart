<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * A shipment the shop booked with a courier for an order, through the carrier's shipping method: its number,
 * the link the customer follows it by, and where the courier last said it was.
 */
#[ORM\Entity]
#[ORM\Table(name: 'consignments')]
#[ORM\Index(name: 'consignment_order', columns: ['order_id'])]
#[ORM\Index(name: 'consignment_status', columns: ['status'])]
class Consignment
{
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

    public function __construct(
        #[ORM\Column]
        #[References('orders', onDelete: 'CASCADE')]
        private int $orderId,
        #[ORM\Column]
        private int $carrierId,
        #[ORM\Column(length: 100)]
        private string $carrier,
        #[ORM\Column(length: 40)]
        private string $method,
        #[ORM\Column(length: 64)]
        private string $number,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $trackingUrl,
        #[ORM\Column]
        private \DateTimeImmutable $bookedAt,
    ) {
        $this->updatedAt = $bookedAt;
        if ($trackingUrl !== null && !str_starts_with($trackingUrl, 'https://') && !str_starts_with($trackingUrl, 'http://')) {
            $this->trackingUrl = null;
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The consignment has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function carrierId(): int
    {
        return $this->carrierId;
    }

    /** The carrier's name when it was booked. */
    public function carrier(): string
    {
        return $this->carrier;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function trackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function status(): ConsignmentStatus
    {
        return $this->status;
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
}
