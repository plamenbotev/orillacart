<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardCode;
use OrillaCart\GiftCards\Domain\GiftCardEntry;
use OrillaCart\GiftCards\Domain\GiftCardKind;
use OrillaCart\GiftCards\Domain\GiftCardRepository;

final readonly class DoctrineGiftCardRepository implements GiftCardRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id, bool $lock = false): ?GiftCard
    {
        return $this->locked($this->entityManager->find(GiftCard::class, $id), $lock);
    }

    public function withCode(GiftCardCode $code, bool $lock = false): ?GiftCard
    {
        return $this->locked($this->entityManager->getRepository(GiftCard::class)->findOneBy(['codeHash' => $code->hash()]), $lock);
    }

    public function storeCreditOf(int $customerId, bool $lock = false): ?GiftCard
    {
        return $this->locked($this->entityManager->getRepository(GiftCard::class)->findOneBy(['customerId' => $customerId, 'kind' => GiftCardKind::StoreCredit]), $lock);
    }

    public function storeCreditFor(int $customerId, string $currency, \DateTimeImmutable $now): GiftCard
    {
        $credit = $this->storeCreditOf($customerId, true);
        if ($credit !== null) {
            return $credit;
        }
        // The unique key on customer and kind: a row another first credit made meanwhile is waited for, and kept.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO ' . $this->entityManager->getClassMetadata(GiftCard::class)->getTableName()
            . ' (code_hash, code_end, initial, balance, expires_at, active, recipient_name, recipient_email, message, kind, currency, customer_id, order_id, created_at)'
            . " VALUES (NULL, '', 0, 0, NULL, 1, '', '', '', ?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE id = id",
            [GiftCardKind::StoreCredit->value, $currency, $customerId, $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, Types::DATETIME_IMMUTABLE],
        );

        return $this->storeCreditOf($customerId, true) ?? throw new \LogicException("The store credit of customer {$customerId} was made and is not there.");
    }

    public function page(string $search, int $page, int $perPage): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(GiftCard::class, 'c')
            ->where('c.kind = :kind')
            ->setParameter('kind', GiftCardKind::GiftCard)
            ->orderBy('c.id', 'DESC');
        if ($search !== '') {
            $query->andWhere('c.recipientEmail LIKE :search OR c.recipientName LIKE :search OR c.codeEnd = :end')
                ->setParameter('search', '%' . addcslashes($search, '%_\\') . '%')
                ->setParameter('end', strtoupper(substr(str_replace(['-', ' '], '', $search), -4)));
        }
        $total = (int) (clone $query)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<GiftCard> $cards */
        $cards = $query->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return ['cards' => $cards, 'total' => $total];
    }

    public function boughtWith(int $orderId): array
    {
        return array_values($this->entityManager->getRepository(GiftCard::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']));
    }

    public function toRecipient(string $email): array
    {
        /** @var list<GiftCard> $cards */
        $cards = $this->entityManager->createQueryBuilder()->select('c')->from(GiftCard::class, 'c')
            ->where('LOWER(c.recipientEmail) = :email')->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()->getResult();

        return $cards;
    }

    public function save(GiftCard $card): void
    {
        $this->entityManager->persist($card);
        $this->entityManager->flush();
    }

    public function add(GiftCardEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function entries(int $cardId): array
    {
        return array_values($this->entityManager->getRepository(GiftCardEntry::class)->findBy(['cardId' => $cardId], ['id' => 'ASC']));
    }

    public function entriesOfOrder(int $orderId): array
    {
        return array_values($this->entityManager->getRepository(GiftCardEntry::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']));
    }

    private function locked(?GiftCard $card, bool $lock): ?GiftCard
    {
        if ($card !== null && $lock) {
            $this->entityManager->refresh($card, LockMode::PESSIMISTIC_WRITE);
        }

        return $card;
    }
}
