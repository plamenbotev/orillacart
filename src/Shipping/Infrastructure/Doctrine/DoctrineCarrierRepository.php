<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;

/**
 * The carriers with their rates, read once per request (a checkout asks for them several times); a save or a
 * removal reads them anew.
 */
final class DoctrineCarrierRepository implements CarrierRepository
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'carriers';

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
        /** @var list<Carrier> */
        return $this->kept(fn(): mixed => $this->entityManager->createQueryBuilder()
            ->select('c', 'r')
            ->from(Carrier::class, 'c')
            ->leftJoin('c.rates', 'r')
            ->orderBy('c.position')
            ->addOrderBy('c.name')
            ->addOrderBy('c.id')
            ->getQuery()
            ->getResult());
    }

    public function find(int $id): ?Carrier
    {
        return $this->entityManager->find(Carrier::class, $id);
    }

    public function save(Carrier $carrier): void
    {
        $this->entityManager->persist($carrier);
        $this->entityManager->flush();
        $this->forget();
    }

    public function remove(Carrier $carrier): void
    {
        $this->entityManager->remove($carrier);
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
