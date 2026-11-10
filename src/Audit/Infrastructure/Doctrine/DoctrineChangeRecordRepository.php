<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
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
        $ids = $paths === [] ? [] : $this->onPaths($paths);
        if ($mention !== null && trim($mention) !== '') {
            array_push($ids, ...$this->mentioning(trim($mention)));
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return 0;
        }
        $changed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $updated = $this->entityManager->createQueryBuilder()->update(ChangeRecord::class, 'r')->set('r.values', ':values')
                ->setParameter('values', $values, Types::JSON)
                ->where('r.id IN (:ids)')->setParameter('ids', $chunk, ArrayParameterType::INTEGER)
                ->getQuery()->execute();
            $changed += \is_int($updated) ? $updated : 0;
        }

        return $changed;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<int>
     */
    private function onPaths(array $paths): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('r.id')->from(ChangeRecord::class, 'r');
        $which = $query->expr()->orX();
        foreach (array_values($paths) as $i => $path) {
            $which->add("(r.path = :path{$i} OR r.path LIKE :below{$i})");
            $query->setParameter("path{$i}", $path)->setParameter("below{$i}", addcslashes($path, '%_\\') . '/%');
        }

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $query->where($which)->getQuery()->getSingleColumnResult()));
    }

    /**
     * The records whose values name the email, whole: the database finds those it is anywhere in, as a JSON text
     * keeps it (MySQL with its characters as they are; MariaDB as encoded, slashes and accents escaped), and
     * ChangeRecord::mentions() keeps those that name it and not a longer address.
     *
     * @return list<int>
     */
    private function mentioning(string $email): array
    {
        $email = mb_strtolower($email);
        $forms = array_values(array_unique([
            trim(json_encode($email, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '"'),
            trim(json_encode($email, \JSON_THROW_ON_ERROR), '"'),
        ]));
        $query = $this->entityManager->createQueryBuilder()->select('r')->from(ChangeRecord::class, 'r');
        $which = $query->expr()->orX();
        foreach ($forms as $i => $form) {
            $which->add("LOWER(r.values) LIKE :mention{$i}");
            $query->setParameter("mention{$i}", '%' . addcslashes($form, '%_\\') . '%');
        }
        $ids = [];
        foreach ($query->where($which)->getQuery()->toIterable() as $record) {
            if ($record instanceof ChangeRecord && ChangeRecord::mentions($record->values(), $email)) {
                $ids[] = $record->id();
            }
            if ($record instanceof ChangeRecord) {
                $this->entityManager->detach($record);
            }
        }

        return $ids;
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        $removed = $this->entityManager->createQueryBuilder()->delete(ChangeRecord::class, 'r')
            ->where('r.madeAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();

        return \is_int($removed) ? $removed : 0;
    }
}
