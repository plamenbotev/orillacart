<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Agents\Domain\AgentCheckout;
use OrillaCart\Agents\Domain\AgentCheckoutRepository;

final readonly class DoctrineAgentCheckoutRepository implements AgentCheckoutRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * Read anew when this entity manager holds it already: the order that completes it is recorded by whatever
     * placed it, which may be another process than the one the agent asks (a long-running worker, a test's).
     */
    public function find(string $id): ?AgentCheckout
    {
        if (preg_match('/^' . AgentCheckout::ID_PATTERN . '$/', $id) !== 1) {
            return null;
        }
        $held = $this->entityManager->getUnitOfWork()->tryGetById($id, AgentCheckout::class);
        if ($held instanceof AgentCheckout) {
            $this->entityManager->refresh($held);

            return $held;
        }

        return $this->entityManager->find(AgentCheckout::class, $id);
    }

    public function forCart(int $cartId): ?AgentCheckout
    {
        return $this->entityManager->getRepository(AgentCheckout::class)->findOneBy(['cartId' => $cartId], ['createdAt' => 'DESC']);
    }

    public function save(AgentCheckout $checkout): void
    {
        $this->entityManager->persist($checkout);
        $this->entityManager->flush();
    }

    public function forgetOlderThan(\DateTimeImmutable $before): int
    {
        $removed = $this->entityManager->createQueryBuilder()->delete(AgentCheckout::class, 'c')
            ->where('c.expiresAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();

        return \is_int($removed) ? $removed : 0;
    }
}
