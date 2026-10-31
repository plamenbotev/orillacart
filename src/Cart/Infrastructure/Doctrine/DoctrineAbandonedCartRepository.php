<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Cart\Domain\AbandonedCart;
use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Shared\Domain\Money;

final readonly class DoctrineAbandonedCartRepository implements AbandonedCartRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?AbandonedCart
    {
        return $this->entityManager->find(AbandonedCart::class, $id);
    }

    public function save(AbandonedCart $abandoned): void
    {
        $this->entityManager->persist($abandoned);
        $this->entityManager->flush();
    }

    public function ofOrder(int $orderId): ?AbandonedCart
    {
        return $this->entityManager->getRepository(AbandonedCart::class)->findOneBy(['orderId' => $orderId]);
    }

    public function leftSince(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<Cart> */
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Cart::class, 'c')
            ->where('c.checkoutAt IS NOT NULL')
            ->andWhere('c.abandonedId IS NULL')
            ->andWhere('c.checkoutAt < :before')
            ->andWhere('c.updatedAt < :before')
            ->andWhere('SIZE(c.lines) > 0')
            ->andWhere('c.placingSince IS NULL OR c.placingSince < :before')
            ->setParameter('before', $before)
            ->orderBy('c.checkoutAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->entityManager->createQueryBuilder()
            ->select(
                'COUNT(a.id) AS abandoned',
                'COALESCE(SUM(a.subtotal), 0) AS value',
                'COALESCE(SUM(CASE WHEN a.remindedAt IS NOT NULL THEN 1 ELSE 0 END), 0) AS reminded',
                // Recovered once the order placed from it was paid, not when it was placed.
                'COALESCE(SUM(CASE WHEN a.recoveredAt IS NOT NULL THEN 1 ELSE 0 END), 0) AS recovered',
                'COALESCE(SUM(CASE WHEN a.recoveredAt IS NOT NULL THEN a.orderTotal ELSE 0 END), 0) AS recovered_value',
                'COALESCE(SUM(CASE WHEN a.recoveredAt IS NOT NULL AND a.remindedAt IS NOT NULL THEN 1 ELSE 0 END), 0) AS recovered_after_reminder',
            )
            ->from(AbandonedCart::class, 'a')
            ->where('a.abandonedAt >= :from')
            ->andWhere('a.abandonedAt < :until')
            ->andWhere('a.currency = :currency')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('currency', $currency)
            ->getQuery()
            ->getSingleResult();

        return [
            'abandoned' => self::int($row['abandoned'] ?? 0),
            'value' => (string) Money::of(self::decimal($row['value'] ?? '0')),
            'reminded' => self::int($row['reminded'] ?? 0),
            'recovered' => self::int($row['recovered'] ?? 0),
            'recovered_value' => (string) Money::of(self::decimal($row['recovered_value'] ?? '0')),
            'recovered_after_reminder' => self::int($row['recovered_after_reminder'] ?? 0),
        ];
    }

    public function latest(\DateTimeImmutable $from, \DateTimeImmutable $until, string $currency, int $limit): array
    {
        /** @var list<AbandonedCart> */
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AbandonedCart::class, 'a')
            ->where('a.abandonedAt >= :from')
            ->andWhere('a.abandonedAt < :until')
            ->andWhere('a.currency = :currency')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('currency', $currency)
            ->orderBy('a.abandonedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function claimReminder(int $id, \DateTimeImmutable $at): bool
    {
        $claimed = self::int($this->entityManager->createQueryBuilder()
            ->update(AbandonedCart::class, 'a')
            ->set('a.remindedAt', ':at')
            ->where('a.id = :id')
            ->andWhere('a.remindedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->getQuery()
            ->execute()) === 1;
        $this->refresh($id);

        return $claimed;
    }

    public function releaseReminder(int $id): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(AbandonedCart::class, 'a')
            ->set('a.remindedAt', 'NULL')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
        $this->refresh($id);
    }

    /** The statement went past the entity a request may hold: it reads the row again. */
    private function refresh(int $id): void
    {
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($id, AbandonedCart::class);
        if ($loaded instanceof AbandonedCart) {
            $this->entityManager->refresh($loaded);
        }
    }

    public function ofEmail(string $email): array
    {
        return $this->entityManager->getRepository(AbandonedCart::class)->findBy(['email' => $email], ['abandonedAt' => 'ASC']);
    }

    public function forgetEmail(string $email): int
    {
        $carts = $this->entityManager->createQueryBuilder()
            ->update(Cart::class, 'c')
            ->set('c.contactEmail', 'NULL')
            ->set('c.remind', ':no')
            ->where('c.contactEmail = :email')
            ->setParameter('no', false)
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
        $left = $this->entityManager->createQueryBuilder()
            ->update(AbandonedCart::class, 'a')
            ->set('a.email', 'NULL')
            ->where('a.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();

        return self::int($carts) + self::int($left);
    }

    public function forgetEmailsBefore(\DateTimeImmutable $before): int
    {
        return self::int($this->entityManager->createQueryBuilder()
            ->update(AbandonedCart::class, 'a')
            ->set('a.email', 'NULL')
            ->where('a.email IS NOT NULL')
            ->andWhere('a.abandonedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute());
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function decimal(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }

    public function forgetCustomer(int $customerId): void
    {
        $this->entityManager->createQuery('UPDATE ' . AbandonedCart::class . ' r SET r.customerId = NULL WHERE r.customerId = :customer')->setParameter('customer', $customerId)->execute();
    }
}
