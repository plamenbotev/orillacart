<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A key an integration (an accounting app, a warehouse, a script) calls the shop's API with, as the shop manager
 * who made it, within its scope. Only the key's hash is kept; its first characters name it on the screen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
#[ORM\UniqueConstraint(name: 'api_key_hash', columns: ['hash'])]
class ApiKey
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * @param string $prefix the key's first characters, to tell it from the others: "oc_3f9a1c2b"
     * @param string $hash   the key's SHA-256
     *
     * @throws IntegrationRuleViolation for a name empty or too long
     */
    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column]
        private int $userId,
        #[ORM\Column(length: 20, enumType: ApiKeyScope::class)]
        private ApiKeyScope $scope,
        #[ORM\Column(length: 16)]
        private string $prefix,
        #[ORM\Column(length: 64)]
        private string $hash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = trim($name);
        if ($this->name === '' || mb_strlen($this->name) > self::MAX_NAME_LENGTH) {
            throw new IntegrationRuleViolation('Name the key in 1 to %d characters.', 'orillacart_invalid_api_key_name', values: [self::MAX_NAME_LENGTH]);
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The key has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function scope(): ApiKeyScope
    {
        return $this->scope;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /** @return bool whether it was not used within the minute before, so the use is worth saving */
    public function use(\DateTimeImmutable $at): bool
    {
        if ($this->lastUsedAt !== null && $at->getTimestamp() - $this->lastUsedAt->getTimestamp() < 60) {
            return false;
        }
        $this->lastUsedAt = $at;

        return true;
    }
}
