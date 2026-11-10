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
 *
 * A key is its maker's: only they, or an administrator of the site, see it listed, choose what its assistant may
 * do, or revoke it. Another manager is told there is no such key, so as not to learn whose keys there are.
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
     * The keys the user may manage: their own, or every key for an administrator.
     *
     * @return list<ApiKeyView> newest first
     */
    public function all(int $userId, bool $administrator): array
    {
        $keys = array_values(array_filter($this->keys->all(), static fn(ApiKey $key): bool => $administrator || $key->userId() === $userId));
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
     * The key an OAuth client connects with, as the owner said yes to it: theirs from before for that client, with
     * the scope they chose now, or a new one. Its secret is never shown; the client gets tokens instead.
     *
     * @return int the key's id
     */
    public function connect(string $client, string $name, int $userId, ApiKeyScope $scope): int
    {
        $key = $this->keys->ofClient($client, $userId);
        if ($key === null) {
            $secret = self::PREFIX . bin2hex(random_bytes(20));
            $key = new ApiKey(mb_substr($name, 0, ApiKey::MAX_NAME_LENGTH), $userId, $scope, substr($secret, 0, 11), hash('sha256', $secret), $this->clock->now());
        }
        $key->connect($client, $scope);
        $this->keys->save($key);

        return $key->id();
    }

    /**
     * @throws IntegrationRuleViolation for a key that is not there, or not the user's to manage
     */
    public function revoke(int $id, int $userId, bool $administrator): void
    {
        $this->keys->remove($this->managed($id, $userId, $administrator));
    }

    /** A deleted user's keys stop working and go: their OAuth tokens with them. */
    public function revokeOfUser(int $userId): void
    {
        foreach ($this->keys->all() as $key) {
            if ($key->userId() === $userId) {
                $this->keys->remove($key);
            }
        }
    }

    /**
     * What the owner chose for the AI assistant that uses the key; null for the defaults.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws IntegrationRuleViolation for a key that is not there, or not the user's to manage
     */
    public function assistant(int $id, int $userId, bool $administrator): ?array
    {
        return $this->managed($id, $userId, $administrator)->assistant();
    }

    /**
     * @param array<array-key, mixed> $choices
     *
     * @throws IntegrationRuleViolation for a key that is not there, or not the user's to manage
     */
    public function chooseForAssistant(int $id, array $choices, int $userId, bool $administrator): void
    {
        $key = $this->managed($id, $userId, $administrator);
        $key->chooseForAssistant($choices);
        $this->keys->save($key);
    }

    /**
     * The key, when it is the user's or the user is an administrator.
     *
     * @throws IntegrationRuleViolation for a key that is not there, or not the user's to manage
     */
    private function managed(int $id, int $userId, bool $administrator): ApiKey
    {
        $key = $this->keys->find($id);
        if ($key === null || (!$administrator && $key->userId() !== $userId)) {
            throw new IntegrationRuleViolation('There is no such key.', 'orillacart_unknown_api_key');
        }

        return $key;
    }

    /** Who the key acts as, and what it may do; null for a key the shop has not (made, or revoked since). */
    public function authenticate(#[\SensitiveParameter] string $secret): ?AuthenticatedKey
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
