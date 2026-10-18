<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Coupons\Domain\Coupon;
use OrillaCart\Coupons\Domain\CouponRepository;

final readonly class DoctrineCouponRepository implements CouponRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

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

    public function search(?string $search, int $page, int $perPage): array
    {
        $builder = $this->entityManager->createQueryBuilder()->select('c')->from(Coupon::class, 'c');
        if ($search !== null && trim($search) !== '') {
            $builder->where('c.code LIKE :search OR c.description LIKE :search')->setParameter('search', '%' . addcslashes(trim($search), '%_\\') . '%');
        }
        $count = (clone $builder)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Coupon> $coupons */
        $coupons = $builder->orderBy('c.id', 'DESC')->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$coupons, is_numeric($count) ? (int) $count : 0];
    }

    public function save(Coupon $coupon): void
    {
        $this->entityManager->persist($coupon);
        $this->entityManager->flush();
    }

    public function delete(Coupon $coupon): void
    {
        $this->entityManager->remove($coupon);
        $this->entityManager->flush();
    }
}
