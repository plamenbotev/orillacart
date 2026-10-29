<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Translations\Domain\ServiceCopy;
use OrillaCart\Translations\Domain\Translation;
use OrillaCart\Translations\Domain\TranslationRepository;

final readonly class DbalTranslationRepository implements TranslationRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function values(string $type, array $ids, string $field, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT object_id, value FROM {$this->table()} WHERE object_type = ? AND field = ? AND locale = ? AND object_id IN (?)",
            [$type, $field, $locale, $ids],
            [3 => ArrayParameterType::INTEGER],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[self::int($row['object_id'])] = self::text($row['value']);
        }

        return $values;
    }

    public function inLocale(string $type, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT object_id, field, value FROM {$this->table()} WHERE object_type = ? AND locale = ? AND object_id IN (?)",
            [$type, $locale, $ids],
            [2 => ArrayParameterType::INTEGER],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[self::int($row['object_id'])][self::text($row['field'])] = self::text($row['value']);
        }

        return $values;
    }

    public function all(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT object_id, field, locale, value FROM {$this->table()} WHERE object_type = ? AND object_id IN (?) ORDER BY object_id, field, locale",
            [$type, $ids],
            [1 => ArrayParameterType::INTEGER],
        );
        $all = [];
        foreach ($rows as $row) {
            $all[self::int($row['object_id'])][self::text($row['field'])][self::text($row['locale'])] = self::text($row['value']);
        }

        return $all;
    }

    public function replace(string $type, int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        // All or none: a half-saved set of languages would mix the old and the new.
        $this->connection()->transactional(function (Connection $connection) use ($type, $id, $fields): void {
            $connection->executeStatement(
                "DELETE FROM {$this->table()} WHERE object_type = ? AND object_id = ? AND field IN (?)",
                [$type, $id, array_keys($fields)],
                [2 => ArrayParameterType::STRING],
            );
            foreach ($fields as $field => $locales) {
                foreach ($locales as $locale => $value) {
                    if ($value !== '') {
                        $connection->insert($this->table(), ['object_type' => $type, 'object_id' => $id, 'field' => $field, 'locale' => $locale, 'value' => $value]);
                    }
                }
            }
        });
    }

    public function forget(string $type, array $ids): void
    {
        if ($ids !== []) {
            foreach ([$this->table(), $this->copies()] as $table) {
                $this->connection()->executeStatement("DELETE FROM {$table} WHERE object_type = ? AND object_id IN (?)", [$type, $ids], [1 => ArrayParameterType::INTEGER]);
            }
        }
    }

    public function serviceCopies(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $copies = [];
        foreach ($this->connection()->fetchAllAssociative("SELECT object_id, field, locale, value FROM {$this->copies()} WHERE object_type = ? AND object_id IN (?)", [$type, $ids], [1 => ArrayParameterType::INTEGER]) as $row) {
            $copies[self::int($row['object_id'])][self::text($row['field'])][self::text($row['locale'])] = self::text($row['value']);
        }

        return $copies;
    }

    public function keepServiceCopies(string $type, int $id, array $fields): void
    {
        $this->connection()->transactional(function (Connection $connection) use ($type, $id, $fields): void {
            foreach ($fields as $field => $locales) {
                foreach ($locales as $locale => $value) {
                    $connection->executeStatement("DELETE FROM {$this->copies()} WHERE object_type = ? AND object_id = ? AND field = ? AND locale = ?", [$type, $id, $field, $locale]);
                    $connection->insert($this->copies(), ['object_type' => $type, 'object_id' => $id, 'field' => $field, 'locale' => $locale, 'value' => $value]);
                }
            }
        });
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(Translation::class)->getTableName();
    }

    private function copies(): string
    {
        return $this->entityManager->getClassMetadata(ServiceCopy::class)->getTableName();
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
