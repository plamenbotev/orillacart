<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use OrillaCart\Packages\Application\Dto\ObjectQuery;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Domain\ExtensionObject;
use OrillaCart\Packages\Domain\ExtensionValue;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use Psr\Clock\ClockInterface;

/**
 * The extensions' records in two tables: the objects with their data and references, and the indexed values the
 * queries filter and sort by, kept beside each write.
 */
final readonly class DoctrineObjectStore implements ObjectStore
{
    public function __construct(private EntityManagerInterface $entityManager, private ClockInterface $clock) {}

    public function create(string $extension, string $type, array $data, array $indexed): array
    {
        $this->referencesExist($data);
        $object = new ExtensionObject($extension, $type, $data, $this->clock->now());
        $this->entityManager->persist($object);
        $this->entityManager->flush();
        $this->index($object, $indexed);

        return $object->toArray();
    }

    public function find(string $extension, string $type, int $id): ?array
    {
        return $this->object($extension, $type, $id)?->toArray();
    }

    public function update(string $extension, string $type, int $id, array $data, array $indexed): ?array
    {
        $object = $this->object($extension, $type, $id);
        if ($object === null) {
            return null;
        }
        $this->referencesExist($data + $object->data());
        $object->change($data + $object->data(), $this->clock->now());
        $this->entityManager->flush();
        $this->index($object, $indexed);

        return $object->toArray();
    }

    public function delete(string $extension, string $type, int $id): bool
    {
        $object = $this->object($extension, $type, $id);
        if ($object === null) {
            return false;
        }
        $this->entityManager->remove($object);
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.extension = :e AND v.kind = :k AND v.ownerId = :id')
            ->setParameter('e', $extension)->setParameter('k', ExtensionValue::OBJECT)->setParameter('id', $id)->execute();
        $this->entityManager->flush();

        return true;
    }

    public function query(string $extension, string $type, ObjectQuery $query): array
    {
        $builder = $this->filtered($extension, $type, $query);
        if ($query->after !== null) {
            $builder->andWhere($query->descending ? 'o.id < :after' : 'o.id > :after')->setParameter('after', $query->after);
        }
        $direction = $query->descending ? 'DESC' : 'ASC';
        if (\in_array($query->orderBy, ['id', 'created_at', 'updated_at'], true)) {
            $builder->orderBy('o.' . lcfirst(str_replace('_', '', ucwords($query->orderBy, '_'))), $direction);
        } else {
            $builder->leftJoin(ExtensionValue::class, 's', 'WITH', 's.extension = o.extension AND s.kind = :objectKind AND s.ownerId = o.id AND s.field = :sortField')
                ->setParameter('objectKind', ExtensionValue::OBJECT)->setParameter('sortField', $query->orderBy)
                ->orderBy('s.valueNumber', $direction)->addOrderBy('s.valueText', $direction)->addOrderBy('o.id', $direction);
        }
        /** @var list<ExtensionObject> $objects */
        $objects = $builder->setMaxResults($query->limit)->getQuery()->getResult();

        return array_map(static fn(ExtensionObject $object): array => $object->toArray(), $objects);
    }

    public function count(string $extension, string $type, ObjectQuery $query): int
    {
        $count = $this->filtered($extension, $type, $query)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function fieldValue(string $extension, string $kind, int $ownerId, string $field): mixed
    {
        $value = $this->entityManager->getRepository(ExtensionValue::class)->findOneBy(['extension' => $extension, 'kind' => $kind, 'ownerId' => $ownerId, 'field' => $field]);

        return $value?->value();
    }

    public function fieldsOf(string $extension, string $kind, int $ownerId): array
    {
        $fields = [];
        foreach ($this->entityManager->getRepository(ExtensionValue::class)->findBy(['extension' => $extension, 'kind' => $kind, 'ownerId' => $ownerId]) as $value) {
            $fields[$value->field()] = $value->value();
        }

        return $fields;
    }

    public function fieldsOfMany(string $extension, string $kind, array $ownerIds): array
    {
        $fields = [];
        foreach ($this->entityManager->getRepository(ExtensionValue::class)->findBy(['extension' => $extension, 'kind' => $kind, 'ownerId' => $ownerIds]) as $value) {
            $fields[$value->ownerId()][$value->field()] = $value->value();
        }

        return $fields;
    }

    public function setField(string $extension, string $kind, int $ownerId, string $field, mixed $value): void
    {
        $row = $this->entityManager->getRepository(ExtensionValue::class)->findOneBy(['extension' => $extension, 'kind' => $kind, 'ownerId' => $ownerId, 'field' => $field]);
        if ($value === null) {
            if ($row !== null) {
                $this->entityManager->remove($row);
                $this->entityManager->flush();
            }

            return;
        }
        if ($row === null) {
            $row = new ExtensionValue($extension, $kind, $ownerId, $field, $value);
            $this->entityManager->persist($row);
        } else {
            $row->set($value);
        }
        $this->entityManager->flush();
    }

    public function isolated(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            return $work();
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }
    }

    public function intact(): bool
    {
        return $this->entityManager->isOpen();
    }

    /**
     * A record's product and order must be the shop's: the database would refuse one that is not, and nothing could
     * be written after it in the request. Refused here instead, with which.
     *
     * @param array<string, mixed> $data
     */
    private function referencesExist(array $data): void
    {
        $prefix = substr($this->entityManager->getClassMetadata(ExtensionObject::class)->getTableName(), 0, -\strlen('extension_objects'));
        foreach (['product' => 'products', 'order' => 'orders'] as $kind => $table) {
            $id = ExtensionObject::reference($data, $kind);
            if ($id !== null && $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM ' . $prefix . $table . ' WHERE id = ?', [$id]) === false) {
                throw new ScriptError(\sprintf('There is no %s %d in this shop: a record\'s "%s" is one of the shop\'s %ss.', $kind, $id, $kind, $kind));
            }
        }
    }

    public function forgetExtension(string $extension): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.extension = :e')->setParameter('e', $extension)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionObject::class . ' o WHERE o.extension = :e')->setParameter('e', $extension)->execute();
    }

    public function forgetCustomer(int $customerId): void
    {
        /** @var list<ExtensionObject> $objects */
        $objects = $this->entityManager->getRepository(ExtensionObject::class)->findBy(['customerId' => $customerId]);
        foreach ($objects as $object) {
            $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.extension = :e AND v.kind = :k AND v.ownerId = :id')
                ->setParameter('e', $object->extension())->setParameter('k', ExtensionValue::OBJECT)->setParameter('id', $object->id())->execute();
        }
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionObject::class . ' o WHERE o.customerId = :c')->setParameter('c', $customerId)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.kind = :k AND v.ownerId = :c')->setParameter('k', 'customer')->setParameter('c', $customerId)->execute();
    }

    public function forgetOrder(int $orderId): void
    {
        /** @var list<ExtensionObject> $objects */
        $objects = $this->entityManager->getRepository(ExtensionObject::class)->findBy(['orderId' => $orderId]);
        foreach ($objects as $object) {
            $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.extension = :e AND v.kind = :k AND v.ownerId = :id')
                ->setParameter('e', $object->extension())->setParameter('k', ExtensionValue::OBJECT)->setParameter('id', $object->id())->execute();
        }
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionObject::class . ' o WHERE o.orderId = :o')->setParameter('o', $orderId)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionValue::class . ' v WHERE v.kind = :k AND v.ownerId = :o')->setParameter('k', 'order')->setParameter('o', $orderId)->execute();
    }

    public function ofOrders(string $extension, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        /** @var list<ExtensionObject> $objects */
        $objects = $this->entityManager->getRepository(ExtensionObject::class)->findBy(['extension' => $extension, 'orderId' => $orderIds], ['id' => 'ASC']);

        return array_map(static fn(ExtensionObject $object): array => $object->toArray(), $objects);
    }

    public function ofCustomer(string $extension, int $customerId): array
    {
        /** @var list<ExtensionObject> $objects */
        $objects = $this->entityManager->getRepository(ExtensionObject::class)->findBy(['extension' => $extension, 'customerId' => $customerId], ['id' => 'ASC']);

        return array_map(static fn(ExtensionObject $object): array => $object->toArray(), $objects);
    }

    public function all(string $extension): array
    {
        /** @var list<ExtensionObject> $objects */
        $objects = $this->entityManager->getRepository(ExtensionObject::class)->findBy(['extension' => $extension], ['id' => 'ASC']);

        return array_map(static fn(ExtensionObject $object): array => $object->toArray(), $objects);
    }

    private static function json(mixed $value): string
    {
        $json = json_encode($value);

        return $json === false ? '' : $json;
    }

    private function object(string $extension, string $type, int $id): ?ExtensionObject
    {
        return $this->entityManager->getRepository(ExtensionObject::class)->findOneBy(['id' => $id, 'extension' => $extension, 'type' => $type]);
    }

    /**
     * Keeps the indexed values of an object as its data is now.
     *
     * @param list<string> $indexed
     */
    private function index(ExtensionObject $object, array $indexed): void
    {
        $data = $object->data();
        foreach ($indexed as $field) {
            $value = $data[$field] ?? null;
            if (\is_array($value) && isset($value['$ref'])) {
                $value = $value['id'] ?? null;
            }
            $this->setField($object->extension(), ExtensionValue::OBJECT, $object->id(), $field, \is_array($value) ? json_encode($value) : $value);
        }
    }

    private function filtered(string $extension, string $type, ObjectQuery $query): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()->select('o')->from(ExtensionObject::class, 'o')
            ->where('o.extension = :extension')->andWhere('o.type = :type')
            ->setParameter('extension', $extension)->setParameter('type', $type);
        foreach ($query->where as $index => [$field, $operator, $value, $kind]) {
            $parameter = 'w' . $index;
            if ($kind === 'id' || \in_array($kind, ['product', 'order', 'customer'], true)) {
                $column = $kind === 'id' ? 'o.id' : 'o.' . $kind . 'Id';
                $id = \is_array($value) ? ($value['id'] ?? ($value['number'] ?? 0)) : $value;
                $builder->andWhere("{$column} {$operator} :{$parameter}")->setParameter($parameter, is_numeric($id) ? (int) $id : 0);
                continue;
            }
            $alias = 'f' . $index;
            $builder->join(ExtensionValue::class, $alias, 'WITH', "{$alias}.extension = o.extension AND {$alias}.kind = :objectKind AND {$alias}.ownerId = o.id AND {$alias}.field = :{$parameter}f")
                ->setParameter('objectKind', ExtensionValue::OBJECT)->setParameter($parameter . 'f', $field);
            // The column follows the field's declared kind, as the value was indexed: "2" in a text field is text.
            if (\in_array($kind, ['number', 'money', 'boolean', 'object'], true)) {
                $id = \is_array($value) ? ($value['id'] ?? 0) : $value;
                $number = \is_bool($id) ? (int) $id : (is_numeric($id) ? (float) $id : (Ops::truthy($id) ? 1 : 0));
                $builder->andWhere("{$alias}.valueNumber {$operator} :{$parameter}")->setParameter($parameter, number_format($number, 4, '.', ''));
            } elseif (\in_array($kind, ['date', 'datetime'], true)) {
                try {
                    $moment = new \DateTimeImmutable(\is_string($value) ? $value : '@0');
                } catch (\Exception) {
                    throw new ScriptError(\sprintf('The field "%s" is compared with a date, not "%s".', $field, \is_scalar($value) ? (string) $value : Ops::kind($value)));
                }
                $builder->andWhere("{$alias}.valueDate {$operator} :{$parameter}")->setParameter($parameter, $moment);
            } else {
                $builder->andWhere("{$alias}.valueText {$operator} :{$parameter}")->setParameter($parameter, mb_substr(\is_string($value) ? $value : (\is_scalar($value) || $value === null ? Ops::text($value) : self::json($value)), 0, ExtensionValue::MAX_TEXT));
            }
        }

        return $builder;
    }
}
