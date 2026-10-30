<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;

/**
 * The shop's search filters, read once per request (a shop page's blocks ask for them several times); a save or a
 * removal reads them anew.
 */
final class DoctrineSearchFilterRepository implements SearchFilterRepository
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'search_filters';

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
        /** @var list<SearchFilter> */
        return $this->kept(fn(): mixed => $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(SearchFilter::class, 'f')
            ->orderBy('f.position')
            ->addOrderBy('f.label')
            ->getQuery()
            ->getResult());
    }

    public function find(int $id): ?SearchFilter
    {
        return $this->entityManager->find(SearchFilter::class, $id);
    }

    public function isKeyTaken(string $key, ?int $exceptId = null): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(SearchFilter::class, 'f')
            ->where('f.filterKey = :key')
            ->setParameter('key', $key);
        if ($exceptId !== null) {
            $query->andWhere('f.id <> :id')->setParameter('id', $exceptId);
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(SearchFilter $filter): void
    {
        $this->entityManager->persist($filter);
        $this->entityManager->flush();
        $this->forget();
    }

    public function remove(SearchFilter $filter): void
    {
        $this->entityManager->remove($filter);
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
