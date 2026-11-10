<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * A risky call waiting for the owner's yes on the shop's own approval page, for a client that cannot ask them
 * itself: what it would do, and their answer once given.
 */
final readonly class CallApproval
{
    public const string WAITING = 'waiting';
    public const string APPROVED = 'approved';
    public const string REFUSED = 'refused';

    public function __construct(
        /** What the approval page is found by: random, never the call itself. */
        #[\SensitiveParameter]
        public string $token,
        public int $userId,
        public ?int $keyId,
        /** The call's digest: its tool and arguments. */
        public string $call,
        /** "Refund order". */
        public string $title,
        /** "Money moves." */
        public string $risk,
        /** What it would do, as the owner reads it. */
        public string $what,
        public string $state,
        public int $expiresAt,
        /** The tool, by name. */
        public string $tool = '',
        /** For how many minutes the owner may also let the key run the tool unasked: 0 when the yes is for this call only. */
        public int $allowFor = 0,
        /** Until when the owner let the key run the tool unasked with their yes; null when it was for this call. */
        public ?int $allowedUntil = null,
    ) {}

    public function answered(bool $yes, ?int $allowedUntil = null): self
    {
        return new self($this->token, $this->userId, $this->keyId, $this->call, $this->title, $this->risk, $this->what, $yes ? self::APPROVED : self::REFUSED, $this->expiresAt, $this->tool, $this->allowFor, $yes ? $allowedUntil : null);
    }

    /** Whether the owner may let the key run the tool unasked for a while with their yes. */
    public function mayAllow(): bool
    {
        return $this->keyId !== null && $this->allowFor > 0 && $this->tool !== '';
    }
}
