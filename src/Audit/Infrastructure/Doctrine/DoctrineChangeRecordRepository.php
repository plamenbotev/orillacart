<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Audit\Domain\ChangeRecordRepository;

final readonly class DoctrineChangeRecordRepository implements ChangeRecordRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(ChangeRecord $record): void
    {
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function search(?string $area, ?int $userId, int $offset, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('r')->from(ChangeRecord::class, 'r');
        if ($area !== null) {
            $query->andWhere('r.area = :area')->setParameter('area', $area);
        }
        if ($userId !== null) {
            $query->andWhere('r.userId = :user')->setParameter('user', $userId);
        }
        $total = (int) (clone $query)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $records = $query->orderBy('r.madeAt', 'DESC')->addOrderBy('r.id', 'DESC')->setFirstResult($offset)->setMaxResults($limit)->getQuery()
            // replaceValues() changes the rows, not the records already loaded
            ->setHint(Query::HINT_REFRESH, true)->getResult();

        return [array_values(array_filter(\is_array($records) ? $records : [], static fn(mixed $record): bool => $record instanceof ChangeRecord)), $total];
    }

    public function areas(): array
    {
        $areas = $this->entityManager->createQueryBuilder()->select('DISTINCT r.area')->from(ChangeRecord::class, 'r')->orderBy('r.area')->getQuery()->getSingleColumnResult();

        return array_values(array_filter($areas, \is_string(...)));
    }

    public function replaceValues(array $paths, ?string $mention, array $values): int
    {
        $query = $this->entityManager->createQueryBuilder()->update(ChangeRecord::class, 'r')->set('r.values', ':values')
            ->setParameter('values', $values, Types::JSON);
        $which = $query->expr()->orX();
        foreach (array_values($paths) as $i => $path) {
            $which->add("(r.path = :path{$i} OR r.path LIKE :below{$i})");
            $query->setParameter("path{$i}", $path)->setParameter("below{$i}", addcslashes($path, '%_\\') . '/%');
        }
        if ($mention !== null && trim($mention) !== '') {
            // A whole JSON string, not a substring: "n@gmail.com" is not in "john@gmail.com". MySQL keeps JSON
            // with its characters as they are; MariaDB keeps the text as encoded, slashes and accents escaped.
            $email = mb_strtolower(trim($mention));
            $forms = array_values(array_unique([
                json_encode($email, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                json_encode($email, \JSON_THROW_ON_ERROR),
            ]));
            foreach ($forms as $i => $form) {
                $which->add("LOWER(r.values) LIKE :mention{$i}");
                $query->setParameter("mention{$i}", '%' . addcslashes($form, '%_\\') . '%');
            }
        }
        if ($which->count() === 0) {
            return 0;
        }
        $changed = $query->where($which)->getQuery()->execute();

        return \is_int($changed) ? $changed : 0;
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        $removed = $this->entityManager->createQueryBuilder()->delete(ChangeRecord::class, 'r')
            ->where('r.madeAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();

        return \is_int($removed) ? $removed : 0;
    }
}
