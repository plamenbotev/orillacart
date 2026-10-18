<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Coupons\Domain\CouponRedemption;
use OrillaCart\Coupons\Domain\RedemptionRepository;

final readonly class DoctrineRedemptionRepository implements RedemptionRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function countByCustomer(int $couponId, ?int $customerId, string $email): int
    {
        $builder = $this->entityManager->createQueryBuilder()->select('COUNT(r.id)')->from(CouponRedemption::class, 'r')
            ->where('r.couponId = :coupon')->setParameter('coupon', $couponId);
        if ($customerId !== null) {
            $builder->andWhere('r.customerId = :customer OR r.email = :email')->setParameter('customer', $customerId);
        } else {
            $builder->andWhere('r.email = :email');
        }
        $count = $builder->setParameter('email', mb_strtolower($email))->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<CouponRedemption> */
        return $this->entityManager->getRepository(CouponRedemption::class)->findBy(['orderId' => $orderId]);
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
