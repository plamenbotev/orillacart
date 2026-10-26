<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Integrations\Application\Dto\ApiKeyView;
use OrillaCart\Integrations\Application\Dto\AuthenticatedKey;
use OrillaCart\Integrations\Application\Dto\CreateApiKey;
use OrillaCart\Integrations\Application\Dto\CreatedApiKeyView;
use OrillaCart\Integrations\Domain\ApiKey;
use OrillaCart\Integrations\Domain\ApiKeyRepository;
use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Domain\IntegrationRuleViolation;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;

/**
 * Keys for integrations to call the shop's API with: "oc_" and 40 random hex characters, of which only the
 * SHA-256 is kept, so the shop shows a key once, when it is made. A key acts as the shop manager who made it,
 * read only or not, until it is revoked.
 */
final readonly class ApiKeys
{
    public const string PREFIX = 'oc_';
    public const string PATTERN = '/^oc_[a-f0-9]{40}$/';

    public function __construct(
        private ApiKeyRepository $keys,
        private UserNames $users,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<ApiKeyView> newest first
     */
    public function all(): array
    {
        $keys = $this->keys->all();
        $names = $this->users->names(array_values(array_unique(array_map(static fn(ApiKey $key): int => $key->userId(), $keys))));

        return array_map(static fn(ApiKey $key): ApiKeyView => ApiKeyView::of($key, $names[$key->userId()] ?? ''), $keys);
    }

    /**
     * @throws IntegrationRuleViolation
     */
    public function create(CreateApiKey $command, int $userId): CreatedApiKeyView
    {
        $secret = self::PREFIX . bin2hex(random_bytes(20));
        $key = new ApiKey($command->name, $userId, ApiKeyScope::from($command->scope), substr($secret, 0, 11), hash('sha256', $secret), $this->clock->now());
        $this->keys->save($key);

        return new CreatedApiKeyView($secret, ApiKeyView::of($key, $this->users->names([$userId])[$userId] ?? ''));
    }

    /**
     * @throws IntegrationRuleViolation for a key that is not there
     */
    public function revoke(int $id): void
    {
        $key = $this->keys->find($id) ?? throw new IntegrationRuleViolation('There is no such key.', 'orillacart_unknown_api_key');
        $this->keys->remove($key);
    }

    /**
     * What the owner chose for the AI assistant that uses the key; null for the defaults.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws IntegrationRuleViolation for a key that is not there
     */
    public function assistant(int $id): ?array
    {
        return ($this->keys->find($id) ?? throw new IntegrationRuleViolation('There is no such key.', 'orillacart_unknown_api_key'))->assistant();
    }

    /**
     * @param array<array-key, mixed> $choices
     *
     * @throws IntegrationRuleViolation for a key that is not there
     */
    public function chooseForAssistant(int $id, array $choices): void
    {
        $key = $this->keys->find($id) ?? throw new IntegrationRuleViolation('There is no such key.', 'orillacart_unknown_api_key');
        $key->chooseForAssistant($choices);
        $this->keys->save($key);
    }

    /** Who the key acts as, and what it may do; null for a key the shop has not (made, or revoked since). */
    public function authenticate(string $secret): ?AuthenticatedKey
    {
        if (preg_match(self::PATTERN, $secret) !== 1) {
            return null;
        }
        $key = $this->keys->withHash(hash('sha256', $secret));
        if ($key === null) {
            return null;
        }
        if ($key->use($this->clock->now())) {
            $this->keys->save($key);
        }

        return new AuthenticatedKey($key->id(), $key->userId(), $key->scope(), $key->name(), $key->assistant());
    }
}
