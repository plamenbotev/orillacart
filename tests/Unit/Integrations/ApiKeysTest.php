<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Integrations;

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\Dto\CreateApiKey;
use OrillaCart\Integrations\Domain\ApiKey;
use OrillaCart\Integrations\Domain\ApiKeyRepository;
use OrillaCart\Integrations\Domain\ApiKeyScope;
use OrillaCart\Integrations\Domain\IntegrationRuleViolation;
use OrillaCart\Shared\Users\UserNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ApiKeysTest extends TestCase
{
    private InMemoryApiKeys $repository;
    private MockClock $clock;
    private ApiKeys $keys;

    protected function setUp(): void
    {
        $this->repository = new InMemoryApiKeys();
        $this->clock = new MockClock('2026-10-21 08:00:00 UTC');
        $this->keys = new ApiKeys($this->repository, new class implements UserNames {
            public function names(array $ids): array
            {
                return [1 => 'Ada'];
            }
        }, $this->clock);
    }

    public function testAKeyIsShownOnceAndOnlyItsHashKept(): void
    {
        $created = $this->keys->create(new CreateApiKey('Accounting', 'read'), 1);

        self::assertMatchesRegularExpression(ApiKeys::PATTERN, $created->key);
        self::assertSame(substr($created->key, 0, 11), $created->apiKey->prefix);
        self::assertSame(['Accounting', 'Ada', 'read'], [$created->apiKey->name, $created->apiKey->user, $created->apiKey->scope]);
        $stored = new \ReflectionProperty(ApiKey::class, 'hash');
        self::assertSame(hash('sha256', $created->key), $stored->getValue($this->repository->keys[0]));
    }

    public function testAKeyActsAsWhoMadeItUntilRevoked(): void
    {
        $created = $this->keys->create(new CreateApiKey('Warehouse', 'read_write'), 1);

        $key = $this->keys->authenticate($created->key);
        self::assertNotNull($key);
        self::assertSame([1, ApiKeyScope::ReadWrite], [$key->userId, $key->scope]);
        self::assertSame('2026-10-21T08:00:00+00:00', $this->keys->all(1, false)[0]->lastUsedAt);
        self::assertNull($this->keys->authenticate('oc_' . str_repeat('0', 40)), 'a key the shop never made');
        self::assertNull($this->keys->authenticate('Bearer nonsense'));

        $this->keys->revoke($created->apiKey->id, 1, false);
        self::assertNull($this->keys->authenticate($created->key));
        $this->expectException(IntegrationRuleViolation::class);
        $this->keys->revoke($created->apiKey->id, 1, false);
    }

    public function testAKeyIsManagedOnlyByWhoMadeItOrAnAdministrator(): void
    {
        $admins = $this->keys->create(new CreateApiKey('Administrator\'s assistant', 'read_write'), 1);
        $managers = $this->keys->create(new CreateApiKey('Manager\'s assistant', 'read_write'), 2);
        $id = $admins->apiKey->id;

        self::assertSame([$managers->apiKey->id], array_map(static fn($key): int => $key->id, $this->keys->all(2, false)), 'a manager lists their own');
        self::assertCount(2, $this->keys->all(1, true), 'an administrator lists every key');
        foreach ([
            fn() => $this->keys->chooseForAssistant($id, ['ask' => []], 2, false),
            fn() => $this->keys->assistant($id, 2, false),
            fn() => $this->keys->revoke($id, 2, false),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Another manager reached the administrator\'s key.');
            } catch (IntegrationRuleViolation $e) {
                self::assertSame('There is no such key.', $e->getMessage(), 'as for a key that is not there');
            }
        }
        self::assertNull($this->keys->assistant($id, 1, false), 'its choices are as they were');
        self::assertNotNull($this->keys->authenticate($admins->key), 'and it still works');

        $this->keys->chooseForAssistant($managers->apiKey->id, ['ask' => []], 1, true);
        self::assertSame(['ask' => []], $this->keys->assistant($managers->apiKey->id, 2, false), 'an administrator manages any key');
        $this->keys->revoke($managers->apiKey->id, 1, true);
        self::assertNull($this->keys->authenticate($managers->key));
    }

    public function testAUseIsSavedAtMostOnceAMinute(): void
    {
        $created = $this->keys->create(new CreateApiKey('Script', 'read'), 1);
        $this->keys->authenticate($created->key);
        $saves = $this->repository->saves;
        $this->clock->sleep(30);
        $this->keys->authenticate($created->key);
        self::assertSame($saves, $this->repository->saves);
        $this->clock->sleep(31);
        $this->keys->authenticate($created->key);
        self::assertSame($saves + 1, $this->repository->saves);
    }
}

final class InMemoryApiKeys implements ApiKeyRepository
{
    /** @var list<ApiKey> */
    public array $keys = [];
    public int $saves = 0;

    public function all(): array
    {
        return array_reverse($this->keys);
    }

    public function find(int $id): ?ApiKey
    {
        foreach ($this->keys as $key) {
            if ($key->id() === $id) {
                return $key;
            }
        }

        return null;
    }

    public function withHash(string $hash): ?ApiKey
    {
        foreach ($this->keys as $key) {
            if (new \ReflectionProperty(ApiKey::class, 'hash')->getValue($key) === $hash) {
                return $key;
            }
        }

        return null;
    }

    public function ofClient(string $client, int $userId): ?ApiKey
    {
        foreach ($this->keys as $key) {
            if ($key->client() === $client && $key->userId() === $userId) {
                return $key;
            }
        }

        return null;
    }

    public function save(ApiKey $key): void
    {
        ++$this->saves;
        if (!\in_array($key, $this->keys, true)) {
            new \ReflectionProperty(ApiKey::class, 'id')->setValue($key, \count($this->keys) + 1);
            $this->keys[] = $key;
        }
    }

    public function remove(ApiKey $key): void
    {
        $this->keys = array_values(array_filter($this->keys, static fn(ApiKey $other): bool => $other !== $key));
    }
}
