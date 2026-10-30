<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Integrations\Domain\ApiKey;
use OrillaCart\Integrations\Domain\OAuthClient;
use OrillaCart\Integrations\Domain\OAuthClientRepository;

final readonly class DoctrineOAuthClientRepository implements OAuthClientRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(string $id): ?OAuthClient
    {
        return $this->entityManager->find(OAuthClient::class, $id);
    }

    public function save(OAuthClient $client): void
    {
        $this->entityManager->persist($client);
        $this->entityManager->flush();
    }

    public function removeUnconnected(\DateTimeImmutable $before): int
    {
        $connected = $this->entityManager->createQueryBuilder()
            ->select('key.client')
            ->from(ApiKey::class, 'key')
            ->where('key.client IS NOT NULL')
            ->getDQL();
        /** @var list<OAuthClient> $stale */
        $stale = $this->entityManager->createQueryBuilder()
            ->select('client')
            ->from(OAuthClient::class, 'client')
            ->where('client.createdAt < :before')
            ->andWhere("client.id NOT IN ({$connected})")
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
        // Through the unit of work, not a bulk DELETE: a client read in this request is forgotten here too.
        foreach ($stale as $client) {
            $this->entityManager->remove($client);
        }
        $this->entityManager->flush();

        return \count($stale);
    }
}
