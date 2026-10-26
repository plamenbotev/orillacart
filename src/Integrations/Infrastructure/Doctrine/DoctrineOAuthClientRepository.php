<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
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
}
