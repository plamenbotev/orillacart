<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The right to download one file of a paid order line, through a secret link: how many downloads are left and
 * until when. Made when the order is paid; gone when it is cancelled or refunded. A download that broke off and
 * resumes within a day is the same download.
 */
#[ORM\Entity]
#[ORM\Table(name: 'download_grants')]
#[ORM\UniqueConstraint(name: 'download_token', columns: ['token'])]
#[ORM\Index(name: 'download_storage', columns: ['storage_key'])]
class DownloadGrant
{
    public const int RESUME_HOURS = 24;
    /** Resumes of one counted download: enough for a connection that keeps breaking, not a second copy a day. */
    public const int RESUMES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $downloads = 0;

    /** When a download was last counted: a browser resuming it soon after is not counted again. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastDownloadedAt = null;

    /** Who the last counted download went to (a hash of their address): only they resume it. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $lastDownloadedBy = null;

    /** How many more times the last counted download may be resumed without counting another. */
    #[ORM\Column(options: ['default' => 0])]
    private int $resumesLeft = 0;

    /**
     * How far into the file the last counted download, with its resumes, was sent: set to the end of what each
     * request asks for when it starts, and lowered to what went out when it breaks off. A resume goes on from
     * about there, so one counted download is one copy of the file.
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $sentUntil = 0;

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
            !$this->order->isPaid() => 'The order is not paid.',
            $this->expiresAt !== null && $this->expiresAt < $now => 'The download link has expired.',
            $this->remaining !== null && $this->remaining < 1 => 'There are no downloads left for this file.',
            default => null,
        };
    }

    public function lastDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->lastDownloadedAt;
    }

    public function lastDownloadedBy(): ?string
    {
        return $this->lastDownloadedBy;
    }

    public function resumesLeft(): int
    {
        return $this->resumesLeft;
    }

    public function sentUntil(): int
    {
        return $this->sentUntil;
    }

    /**
     * What a resume may go back over of what was sent, as bytes on their way are lost when a connection breaks:
     * a tenth of the file, at most a megabyte.
     */
    public static function overlap(int $size): int
    {
        return min(1048576, intdiv(max(0, $size), 10));
    }

    /**
     * Whether the rest of the last counted download can still be fetched without counting another: by whoever it
     * went to, soon after, a few times, from about where it broke off (overlap()), not from the start again.
     *
     * @param int $from the byte the resume starts at
     * @param int $size the file's
     */
    public function resumes(\DateTimeImmutable $now, string $by, int $from = 0, int $size = 0): bool
    {
        return $from + self::overlap($size) >= $this->sentUntil
            && $this->order->isPaid()
            && $this->lastDownloadedBy !== null && hash_equals($this->lastDownloadedBy, $by)
            && $this->resumesLeft > 0
            && ($this->expiresAt === null || $this->expiresAt >= $now)
            && $this->lastDownloadedAt !== null
            && $this->lastDownloadedAt > $now->modify(\sprintf('-%d hours', self::RESUME_HOURS));
    }
}
