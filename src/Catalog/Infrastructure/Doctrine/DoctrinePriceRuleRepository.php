<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceRuleRepository;

/**
 * The shop's price rules. The active ones are read once per request, as every price a page shows asks for them;
 * a save or a delete reads them anew.
 */
final class DoctrinePriceRuleRepository implements PriceRuleRepository
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'price_rules_active';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        // What was read goes with the entities: a worker forgets them between batches (LoadedEntities).
        $entityManager->getEventManager()->addEventListener(Events::onClear, $this);
    }

    /** @internal Doctrine's, when the entity manager forgets what it loaded */
    public function onClear(): void
    {
        $this->forget();
    }

    public function all(): array
    {
        /** @var list<PriceRule> */
        return $this->entityManager->getRepository(PriceRule::class)->findBy([], ['name' => 'ASC']);
    }

    public function active(): array
    {
        /** @var list<PriceRule> */
        return $this->kept(fn(): mixed => $this->entityManager->getRepository(PriceRule::class)->findBy(['active' => true], ['id' => 'ASC']));
    }

    public function find(int $id): ?PriceRule
    {
        return $this->entityManager->find(PriceRule::class, $id);
    }

    public function save(PriceRule $rule): void
    {
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $this->forget();
    }

    public function delete(PriceRule $rule): void
    {
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
        $this->forget();
    }

    /**
     * Read once per request: kept in WordPress's cache for this request only (never a persistent object cache),
     * which a worker empties between rounds and a site of a network has its own of.
     *
     * @param \Closure(): mixed $read the query's result, a list
     *
     * @return array<mixed>
     */
    private function kept(\Closure $read): array
    {
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $kept = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (!\is_array($kept)) {
            $kept = $read();
            $kept = \is_array($kept) ? $kept : [];
            wp_cache_set(self::CACHE_KEY, $kept, self::CACHE_GROUP);
        }

        return $kept;
    }

    private function forget(): void
    {
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }
}
