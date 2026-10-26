<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The tokens an OAuth client holds for the key the owner connected it with: an access token for an hour, and a
 * refresh token that gets a new pair once. Only their hashes are kept; revoking the key ends them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_tokens')]
#[ORM\UniqueConstraint(name: 'oauth_access_hash', columns: ['access_hash'])]
#[ORM\UniqueConstraint(name: 'oauth_refresh_hash', columns: ['refresh_hash'])]
class OAuthToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    /** A refresh: a new pair, the old one gone. */
    public function rotate(string $accessHash, \DateTimeImmutable $accessExpiresAt, string $refreshHash, \DateTimeImmutable $refreshExpiresAt): void
    {
        $this->accessHash = $accessHash;
        $this->accessExpiresAt = $accessExpiresAt;
        $this->refreshHash = $refreshHash;
        $this->refreshExpiresAt = $refreshExpiresAt;
    }
}
