<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An address the shop tells of what happens (an order placed, a product changed): each event of its topics is
 * POSTed there as JSON, signed with its secret.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhooks')]
class Webhook
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 500)]
    private string $url;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $topics;

    #[ORM\Column]
    private bool $active;

    /**
     * @param list<string> $topics
     *
     * @throws IntegrationRuleViolation
     */
    public function __construct(
        string $name,
        string $url,
        array $topics,
        bool $active,
        #[ORM\Column(type: 'sealed_string', length: 255)]
        private string $secret,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->change($name, $url, $topics, $active);
    }

    /**
     * @param list<string> $topics
     *
     * @throws IntegrationRuleViolation for no name, an address that is not https (but on this computer), or no topics
     */
    public function change(string $name, string $url, array $topics, bool $active): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new IntegrationRuleViolation('Name the webhook in 1 to %d characters.', 'orillacart_invalid_webhook', values: [self::MAX_NAME_LENGTH]);
        }
        $parts = parse_url(trim($url));
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $local = \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
        if ($host === '' || mb_strlen($url) > 500 || !($scheme === 'https' || ($scheme === 'http' && $local))) {
            throw new IntegrationRuleViolation('A webhook goes to an https:// address (http:// only on this computer).', 'orillacart_invalid_webhook_url');
        }
        if ($topics === []) {
            throw new IntegrationRuleViolation('Choose what the webhook tells of.', 'orillacart_invalid_webhook_topics');
        }
        $this->name = $name;
        $this->url = trim($url);
        $this->topics = array_values(array_unique($topics));
        $this->active = $active;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The webhook has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function topics(): array
    {
        return $this->topics;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function listensTo(string $topic): bool
    {
        return $this->active && \in_array($topic, $this->topics, true);
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function newSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
