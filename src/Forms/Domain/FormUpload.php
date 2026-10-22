<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A file a shopper sent to answer a file field, kept among the shop's private files. Its token is a secret only
 * the sender holds: the answer names the upload by it, so nobody can answer with another's file. An order that
 * takes it keeps it; one no order took is deleted after a while, longer once it is in a cart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'form_uploads')]
#[ORM\UniqueConstraint(name: 'form_upload_token', columns: ['token_hash'])]
#[ORM\Index(name: 'form_upload_order', columns: ['order_id'])]
class FormUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    /** When a cart last took it: kept as long as carts are, not just the days of one never added. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $heldAt = null;

    public function __construct(
        string $token,
        #[ORM\Column(length: 255)]
        private string $storageKey,
        #[ORM\Column(length: 255)]
        private string $filename,
        #[ORM\Column(length: 100)]
        private string $mimeType,
        #[ORM\Column]
        private int $size,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->tokenHash = self::hash($token);
        $this->filename = CleanText::filename($filename);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The upload has no id until it is saved.');
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function heldAt(): ?\DateTimeImmutable
    {
        return $this->heldAt;
    }

    public function hold(\DateTimeImmutable $now): void
    {
        $this->heldAt = $now;
    }

    /** The order that took it keeps it; an upload answers one order only. */
    public function takenBy(int $orderId): void
    {
        if ($this->orderId !== null && $this->orderId !== $orderId) {
            throw new FormRuleViolation('This file belongs to another order; send it again.', 'orillacart_upload_used');
        }
        $this->orderId = $orderId;
    }
}
