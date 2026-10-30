<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Coupons\Domain\CouponRedemption;
use OrillaCart\Coupons\Domain\RedemptionRepository;

final readonly class DoctrineRedemptionRepository implements RedemptionRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function countByCustomer(int $couponId, ?int $customerId, string $email, bool $current = false): int
    {
        $builder = $this->entityManager->createQueryBuilder()->select('COUNT(r.id)')->from(CouponRedemption::class, 'r')
            ->where('r.couponId = :coupon')->setParameter('coupon', $couponId);
        // An empty email is nobody's: uses of an erased customer keep none.
        $email = mb_strtolower($email);
        if ($customerId !== null && $email !== '') {
            $builder->andWhere('r.customerId = :customer OR r.email = :email')->setParameter('customer', $customerId)->setParameter('email', $email);
        } elseif ($customerId !== null) {
            $builder->andWhere('r.customerId = :customer')->setParameter('customer', $customerId);
        } elseif ($email !== '') {
            $builder->andWhere('r.email = :email')->setParameter('email', $email);
        } else {
            return 0;
        }
        $query = $builder->getQuery();
        if ($current) {
            // A locking read sees the uses another order committed while this one waited for the coupon's lock.
            $query->setLockMode(LockMode::PESSIMISTIC_READ);
        }
        $count = $query->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<CouponRedemption> */
        return $this->entityManager->getRepository(CouponRedemption::class)->findBy(['orderId' => $orderId]);
    }

    public function orderIds(?array $couponIds = null): array
    {
        if ($couponIds === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()->select('DISTINCT r.orderId')->from(CouponRedemption::class, 'r');
        if ($couponIds !== null) {
            $query->where('r.couponId IN (:coupons)')->setParameter('coupons', $couponIds);
        }
        $ids = $query->getQuery()->getSingleColumnResult();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    public function save(CouponRedemption $redemption): void
    {
        $this->entityManager->persist($redemption);
        $this->entityManager->flush();
    }

    public function delete(CouponRedemption $redemption): void
    {
        $this->entityManager->remove($redemption);
        $this->entityManager->flush();
    }
}
