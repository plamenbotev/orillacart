<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRepository;

final readonly class DoctrineCouponRepository implements CouponRepository
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'advertised_coupons';

    public function __construct(private EntityManagerInterface $entityManager)
    {
        // What was read goes with the entities: a worker forgets them between batches (LoadedEntities).
        $entityManager->getEventManager()->addEventListener(Events::onClear, $this);
    }

    /** @internal Doctrine's, when the entity manager forgets what it loaded */
    public function onClear(): void
    {
        $this->forget();
    }

    public function find(int $id): ?Coupon
    {
        return $this->entityManager->find(Coupon::class, $id);
    }

    public function findByCodes(array $codes, bool $lock = false): array
    {
        if ($codes === []) {
            return [];
        }
        $result = $this->entityManager->createQuery('SELECT c FROM ' . Coupon::class . ' c WHERE c.code IN (:codes) ORDER BY c.id')
            ->setParameter('codes', $codes)
            ->getResult();
        $found = [];
        foreach (\is_array($result) ? $result : [] as $coupon) {
            if ($coupon instanceof Coupon) {
                if ($lock) {
                    // SELECT … FOR UPDATE, in id order: the counts as they are now, held until the transaction ends.
                    $this->entityManager->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
                }
                $found[$coupon->code()] = $coupon;
            }
        }

        return $found;
    }

    public function search(?string $search, int $page, int $perPage, bool $automatic = false): array
    {
        $builder = $this->entityManager->createQueryBuilder()->select('c')->from(Coupon::class, 'c')
            ->where('c.automatic = :automatic')->setParameter('automatic', $automatic);
        if ($search !== null && trim($search) !== '') {
            $builder->andWhere('c.code LIKE :search OR c.description LIKE :search')->setParameter('search', '%' . addcslashes(trim($search), '%_\\') . '%');
        }
        $count = (clone $builder)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Coupon> $coupons */
        $coupons = $builder->orderBy('c.id', 'DESC')->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$coupons, is_numeric($count) ? (int) $count : 0];
    }

    public function automatic(): array
    {
        /** @var list<Coupon> */
        return $this->entityManager->getRepository(Coupon::class)->findBy(['automatic' => true, 'active' => true], ['id' => 'ASC']);
    }

    public function advertised(): array
    {
        // Read once per request: every card's Product promotions block asks. Kept in WordPress's cache for this
        // request only (never a persistent object cache), which a site of a network has its own of.
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $kept = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (!\is_array($kept)) {
            $kept = $this->entityManager->getRepository(Coupon::class)->findBy(['advertised' => true, 'active' => true], ['id' => 'ASC']);
            wp_cache_set(self::CACHE_KEY, $kept, self::CACHE_GROUP);
        }

        /** @var list<Coupon> */
        return $kept;
    }

    public function firstOrderOnlyIds(): array
    {
        $ids = $this->entityManager->createQuery('SELECT c.id FROM ' . Coupon::class . ' c WHERE c.firstOrderOnly = true')->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    public function save(Coupon $coupon): void
    {
        $this->entityManager->persist($coupon);
        $this->entityManager->flush();
        $this->forget();
    }

    public function delete(Coupon $coupon): void
    {
        $this->entityManager->remove($coupon);
        $this->entityManager->flush();
        $this->forget();
    }

    private function forget(): void
    {
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }
}
