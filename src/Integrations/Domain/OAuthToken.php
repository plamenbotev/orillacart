<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The tokens an OAuth client holds for the key the owner connected it with: an access token for an hour, and a
 * refresh token that gets a new pair once. Only their hashes are kept; revoking the key ends them. The refresh
 * token a rotation replaced is remembered by its hash, so one presented again is told apart from an unknown one:
 * it means the token leaked, and the whole grant ends (OAuth 2.1 §6.1) — unless it comes within a minute of the
 * rotation, as when a client sent two refreshes at once: that one is refused and the grant kept.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_tokens')]
#[ORM\UniqueConstraint(name: 'oauth_access_hash', columns: ['access_hash'])]
#[ORM\UniqueConstraint(name: 'oauth_refresh_hash', columns: ['refresh_hash'])]
#[ORM\Index(name: 'oauth_previous_refresh_hash', columns: ['previous_refresh_hash'])]
class OAuthToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The hash of the refresh token the last rotation replaced; null until the first. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousRefreshHash = null;

    /** When the last rotation was; null until the first. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ApiKey::class)]
        #[ORM\JoinColumn(name: 'api_key_id', nullable: false, onDelete: 'CASCADE')]
        private ApiKey $key,
        #[ORM\Column(length: 255)]
        private string $clientId,
        #[ORM\Column(length: 64)]
        private string $accessHash,
        #[ORM\Column]
        private \DateTimeImmutable $accessExpiresAt,
        #[ORM\Column(length: 64)]
        private string $refreshHash,
        #[ORM\Column]
        private \DateTimeImmutable $refreshExpiresAt,
    ) {}

    public function key(): ApiKey
    {
        return $this->key;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function accessExpiresAt(): \DateTimeImmutable
    {
        return $this->accessExpiresAt;
    }

    public function refreshExpiresAt(): \DateTimeImmutable
    {
        return $this->refreshExpiresAt;
    }

    /** Whether the refresh token with this hash is the one the token holds now. */
    public function refreshes(string $refreshHash): bool
    {
        return hash_equals($this->refreshHash, $refreshHash);
    }

    /**
     * Whether the refresh token with this hash is the one the last rotation replaced, and that rotation was less
     * than $seconds before $now: a refresh sent at the same time as the one that rotated it, not a leaked token.
     */
    public function replacedJustNow(string $refreshHash, \DateTimeImmutable $now, int $seconds): bool
    {
        return $this->previousRefreshHash !== null && hash_equals($this->previousRefreshHash, $refreshHash)
            && $this->rotatedAt !== null && $now->getTimestamp() - $this->rotatedAt->getTimestamp() < $seconds;
    }

    /** A refresh: a new pair, the old one gone; the old refresh token's hash is kept to recognise it. */
    public function rotate(string $accessHash, \DateTimeImmutable $accessExpiresAt, string $refreshHash, \DateTimeImmutable $refreshExpiresAt, \DateTimeImmutable $now): void
    {
        $this->previousRefreshHash = $this->refreshHash;
        $this->rotatedAt = $now;
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->refreshHash = $refreshHash;
        $this->refreshExpiresAt = $refreshExpiresAt;
    }
}
