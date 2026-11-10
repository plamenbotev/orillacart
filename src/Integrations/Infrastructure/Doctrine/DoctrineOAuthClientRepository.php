<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
        /** @var list<OAuthClient> $stale */
        $stale = $this->unconnected()
            ->select('client')
            ->andWhere('client.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();

        return $this->remove($stale);
    }

    public function countUnconnected(): int
    {
        return (int) $this->unconnected()->select('COUNT(client.id)')->getQuery()->getSingleScalarResult();
    }

    public function removeOldestUnconnected(int $count, \DateTimeImmutable $before): int
    {
        if ($count < 1) {
            return 0;
        }
        /** @var list<OAuthClient> $oldest */
        $oldest = $this->unconnected()
            ->select('client')
            ->andWhere('client.createdAt < :before')
            ->setParameter('before', $before)
            ->orderBy('client.createdAt', 'ASC')
            ->addOrderBy('client.id', 'ASC')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult();

        return $this->remove($oldest);
    }

    /** The clients no key names: the owner never connected them. */
    private function unconnected(): QueryBuilder
    {
        $connected = $this->entityManager->createQueryBuilder()
            ->select('key.client')
            ->from(ApiKey::class, 'key')
            ->where('key.client IS NOT NULL')
            ->getDQL();

        return $this->entityManager->createQueryBuilder()
            ->from(OAuthClient::class, 'client')
            ->where("client.id NOT IN ({$connected})");
    }

    /**
     * @param list<OAuthClient> $stale
     */
    private function remove(array $stale): int
    {
        // Through the unit of work, not a bulk DELETE: a client read in this request is forgotten here too.
        foreach ($stale as $client) {
            $this->entityManager->remove($client);
        }
        $this->entityManager->flush();

        return \count($stale);
    }
}
