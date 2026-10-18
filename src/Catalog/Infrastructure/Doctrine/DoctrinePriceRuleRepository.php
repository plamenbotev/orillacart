<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceRuleRepository;

final readonly class DoctrinePriceRuleRepository implements PriceRuleRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function all(): array
    {
        /** @var list<PriceRule> */
        return $this->entityManager->getRepository(PriceRule::class)->findBy([], ['name' => 'ASC']);
    }

    public function active(): array
    {
        /** @var list<PriceRule> */
        return $this->entityManager->getRepository(PriceRule::class)->findBy(['active' => true], ['id' => 'ASC']);
    }

    public function find(int $id): ?PriceRule
    {
        return $this->entityManager->find(PriceRule::class, $id);
    }

    public function save(PriceRule $rule): void
    {
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
    }

    public function delete(PriceRule $rule): void
    {
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }
}
