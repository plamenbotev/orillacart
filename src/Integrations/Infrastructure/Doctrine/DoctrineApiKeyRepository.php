<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Integrations\Domain\ApiKey;
use OrillaCart\Integrations\Domain\ApiKeyRepository;

final readonly class DoctrineApiKeyRepository implements ApiKeyRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        return array_values($this->entityManager->getRepository(ApiKey::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']));
    }

    public function find(int $id): ?ApiKey
    {
        return $this->entityManager->find(ApiKey::class, $id);
    }

    public function withHash(string $hash): ?ApiKey
    {
        return $this->entityManager->getRepository(ApiKey::class)->findOneBy(['hash' => $hash]);
    }

    public function ofClient(string $client, int $userId): ?ApiKey
    {
        return $this->entityManager->getRepository(ApiKey::class)->findOneBy(['client' => $client, 'userId' => $userId]);
    }

    public function save(ApiKey $key): void
    {
        $this->entityManager->persist($key);
        $this->entityManager->flush();
    }

    public function remove(ApiKey $key): void
    {
        $this->entityManager->remove($key);
        $this->entityManager->flush();
    }
}
