<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Integrations\Domain\OAuthToken;
use OrillaCart\Integrations\Domain\OAuthTokenRepository;

final readonly class DoctrineOAuthTokenRepository implements OAuthTokenRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function withAccessHash(string $hash): ?OAuthToken
    {
        return $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['accessHash' => $hash]);
    }

    public function forRefresh(string $hash): ?OAuthToken
    {
        $repository = $this->entityManager->getRepository(OAuthToken::class);
        $token = $repository->findOneBy(['refreshHash' => $hash]) ?? $repository->findOneBy(['previousRefreshHash' => $hash]);
        if ($token === null) {
            return null;
        }
        // Read again under the row's lock: a refresh that ran meanwhile may have rotated it.
        $this->entityManager->refresh($token, LockMode::PESSIMISTIC_WRITE);

        return $token;
    }

    public function save(OAuthToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function remove(OAuthToken $token): void
    {
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }

    public function removeExpired(\DateTimeImmutable $before): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(OAuthToken::class, 'token')
            ->where('token.refreshExpiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
