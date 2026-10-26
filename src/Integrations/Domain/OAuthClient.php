<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An OAuth client that registered itself (dynamic client registration, RFC 7591): an AI assistant's app that
 * has no client ID metadata document. It is a public client: it proves itself with PKCE, not a secret.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_clients')]
class OAuthClient
{
    public const int MAX_NAME_LENGTH = 200;
    public const int MOST_REDIRECTS = 10;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $id,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\Column(type: 'json')]
        private array $redirectUris,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);
        if ($redirectUris === [] || \count($redirectUris) > self::MOST_REDIRECTS) {
            throw new IntegrationRuleViolation('A client gives 1 to %d redirect addresses.', 'orillacart_invalid_redirect_uri', values: [self::MOST_REDIRECTS]);
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
