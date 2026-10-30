<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use OrillaCart\Platform\Doctrine\SealedJsonType;
use OrillaCart\Platform\Doctrine\SealedStringType;
use OrillaCart\Shared\Security\HoldsSealedValues;
use OrillaCart\Shared\Security\Secrets;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every value the shop keeps sealed: the sealed columns (found through Doctrine's mapping: a webhook's secret, a
 * payment method's and a carrier's credentials) and what else holds some (an option). Those sealed with an earlier
 * key, or never sealed, are sealed again with the current key, for `wp orillacart doctor --fix`; one that no key
 * opens is left as it is, for the owner to enter again.
 */
final readonly class SealedValues
{
    /**
     * @param iterable<HoldsSealedValues> $holders
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Secrets $secrets,
        #[AutowireIterator(HoldsSealedValues::SEALED_VALUES_TAG)]
        private iterable $holders,
    ) {}

    /**
     * @return array{stale: int, unreadable: int} how many are not sealed with the current key but open, and how many no key opens
     */
    public function count(): array
    {
        return $this->walk(false);
    }

    /**
     * @return array{resealed: int, unreadable: int}
     */
    public function reseal(): array
    {
        $counts = $this->walk(true);

        return ['resealed' => $counts['stale'], 'unreadable' => $counts['unreadable']];
    }

    /**
     * @return array{stale: int, unreadable: int}
     */
    private function walk(bool $write): array
    {
        $stale = 0;
        $unreadable = 0;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            [$columnsStale, $columnsUnreadable] = $this->walkColumns($metadata, $write);
            $stale += $columnsStale;
            $unreadable += $columnsUnreadable;
        }
        foreach ($this->holders as $holder) {
            $values = $holder->sealedValues();
            $opens = array_filter($values, fn(string $value): bool => $this->secrets->open($value) !== null);
            $unreadable += \count($values) - \count($opens);
            $toReseal = \count(array_filter($opens, fn(string $value): bool => !$this->secrets->sealedWithCurrentKey($value)));
            $stale += $toReseal;
            if ($write && $toReseal > 0 && \count($opens) === \count($values)) {
                $holder->resealValues();
            }
        }

        return ['stale' => $stale, 'unreadable' => $unreadable];
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array{int, int} stale, unreadable
     */
    private function walkColumns(ClassMetadata $metadata, bool $write): array
    {
        $columns = [];
        foreach ($metadata->fieldMappings as $mapping) {
            if (\in_array($mapping->type, [SealedStringType::NAME, SealedJsonType::NAME], true)) {
                $columns[$mapping->columnName] = $mapping->type;
            }
        }
        $connection = $this->entityManager->getConnection();
        $table = $metadata->getTableName();
        if ($columns === [] || $metadata->isMappedSuperclass || !$connection->createSchemaManager()->tablesExist([$table])) {
            return [0, 0];
        }
        $identifier = $metadata->getIdentifierColumnNames();
        $quoted = array_map($connection->quoteIdentifier(...), [...$identifier, ...array_keys($columns)]);
        $stale = 0;
        $unreadable = 0;
        foreach ($connection->fetchAllAssociative(\sprintf('SELECT %s FROM %s', implode(', ', $quoted), $connection->quoteIdentifier($table))) as $row) {
            foreach ($columns as $column => $type) {
                $kept = $row[$column];
                if (!\is_string($kept)) {
                    continue;
                }
                $sealed = $type === SealedJsonType::NAME ? self::unwrap($kept) : $kept;
                if ($sealed === '' || $this->secrets->sealedWithCurrentKey($sealed)) {
                    continue;
                }
                $plain = $this->secrets->open($sealed);
                if ($plain === null) {
                    ++$unreadable;
                    continue;
                }
                ++$stale;
                if ($write) {
                    $again = $this->secrets->seal($plain);
                    $connection->update($table, [$column => $type === SealedJsonType::NAME ? json_encode(['sealed' => $again], \JSON_THROW_ON_ERROR) : $again], array_intersect_key($row, array_flip($identifier)));
                }
            }
        }

        return [$stale, $unreadable];
    }

    /** The sealed text inside a sealed JSON column's {"sealed": "…"}; JSON kept plain is itself what to seal. */
    private static function unwrap(string $kept): string
    {
        $decoded = json_decode($kept, true);
        if (\is_array($decoded) && array_keys($decoded) === ['sealed'] && \is_string($decoded['sealed'])) {
            return $decoded['sealed'];
        }

        return $kept;
    }
}
