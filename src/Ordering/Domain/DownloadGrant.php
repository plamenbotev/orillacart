<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The right to download one file of a paid order line, through a secret link: how many downloads are left and
 * until when. Made when the order is paid; gone when it is cancelled or refunded.
 */
#[ORM\Entity]
#[ORM\Table(name: 'download_grants')]
#[ORM\UniqueConstraint(name: 'download_token', columns: ['token'])]
#[ORM\Index(name: 'download_storage', columns: ['storage_key'])]
class DownloadGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $downloads = 0;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'grants')]
        #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
        private Order $order,
        #[ORM\Column(length: 64)]
        private string $token,
        #[ORM\Column]
        private int $linePosition,
        #[ORM\Column]
        private int $fileId,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(length: 32)]
        private string $storageKey,
        #[ORM\Column(length: 100)]
        private string $mimeType,
        /** Downloads left; null for no limit. */
        #[ORM\Column(nullable: true)]
        private ?int $remaining,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new \InvalidArgumentException('A download token is 64 hexadecimal characters.');
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The grant has no id until it is saved.');
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function linePosition(): int
    {
        return $this->linePosition;
    }

    public function fileId(): int
    {
        return $this->fileId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function remaining(): ?int
    {
        return $this->remaining;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function downloads(): int
    {
        return $this->downloads;
    }

    /**
     * Sets how many downloads are left and until when, as the shop decides; null for no limit or no end.
     *
     * @throws OrderRuleViolation for a negative number of downloads
     */
    public function allow(?int $remaining, ?\DateTimeImmutable $expiresAt): void
    {
        if ($remaining !== null && $remaining < 0) {
            throw new OrderRuleViolation('The downloads left are 0 or more.', 'orillacart_invalid_download_allowance');
        }
        $this->remaining = $remaining;
        $this->expiresAt = $expiresAt;
    }

    /** Why the link does not work now; null when it does. */
    public function refusal(\DateTimeImmutable $now): ?string
    {
        return match (true) {
            !$this->order->status()->isPaid() => 'The order is not paid.',
            $this->expiresAt !== null && $this->expiresAt < $now => 'The download link has expired.',
            $this->remaining !== null && $this->remaining < 1 => 'There are no downloads left for this file.',
            default => null,
        };
    }
}
