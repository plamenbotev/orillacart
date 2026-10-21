<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Integrations\Domain\Webhook;
use OrillaCart\Integrations\Domain\WebhookDelivery;
use OrillaCart\Integrations\Domain\WebhookRepository;

final readonly class DoctrineWebhookRepository implements WebhookRepository
{
    /** How many deliveries of each webhook are kept, for the screen to show. */
    private const int KEEP = 50;

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        return array_values($this->entityManager->getRepository(Webhook::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']));
    }

    public function find(int $id): ?Webhook
    {
        return $this->entityManager->find(Webhook::class, $id);
    }

    public function save(Webhook $webhook): void
    {
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();
    }

    public function remove(Webhook $webhook): void
    {
        $this->entityManager->createQueryBuilder()->delete(WebhookDelivery::class, 'd')
            ->where('d.webhookId = :webhook')->setParameter('webhook', $webhook->id())
            ->getQuery()->execute();
        $this->entityManager->remove($webhook);
        $this->entityManager->flush();
    }

    public function record(WebhookDelivery $delivery): void
    {
        $this->entityManager->persist($delivery);
        $this->entityManager->flush();
        $oldest = $this->entityManager->createQueryBuilder()->select('d.id')->from(WebhookDelivery::class, 'd')
            ->where('d.webhookId = :webhook')->setParameter('webhook', $delivery->webhookId())
            ->orderBy('d.id', 'DESC')->setFirstResult(self::KEEP - 1)->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if (\is_array($oldest) && is_numeric($oldest['id'] ?? null)) {
            $this->entityManager->createQueryBuilder()->delete(WebhookDelivery::class, 'd')
                ->where('d.webhookId = :webhook')->andWhere('d.id < :oldest')
                ->setParameter('webhook', $delivery->webhookId())->setParameter('oldest', (int) $oldest['id'])
                ->getQuery()->execute();
        }
    }

    public function deliveries(int $webhookId, int $limit): array
    {
        return array_values($this->entityManager->getRepository(WebhookDelivery::class)->findBy(['webhookId' => $webhookId], ['id' => 'DESC'], $limit));
    }
}
