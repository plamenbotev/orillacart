<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Translations;

use OrillaCart\Translations\Application\TranslatableType;
use OrillaCart\Translations\Application\TranslatableTypes;
use OrillaCart\Translations\Domain\TranslationRepository;

/**
 * The translations table in memory, with the types the tests give it.
 */
final class InMemoryTranslations implements TranslationRepository, TranslatableTypes
{
    /** @var array<string, array<int, array<string, array<string, string>>>> by type, id, field and locale */
    private array $rows = [];
    /** @var array<string, TranslatableType> */
    private array $types = [];

    public function add(TranslatableType ...$types): self
    {
        foreach ($types as $type) {
            $this->types[$type->type()] = $type;
        }

        return $this;
    }

    public function get(string $type): ?TranslatableType
    {
        return $this->types[$type] ?? null;
    }

    public function values(string $type, array $ids, string $field, string $locale): array
    {
        $values = [];
        foreach ($ids as $id) {
            $value = $this->rows[$type][$id][$field][$locale] ?? null;
            if ($value !== null) {
                $values[$id] = $value;
            }
        }

        return $values;
    }

    public function all(string $type, array $ids): array
    {
        return array_intersect_key($this->rows[$type] ?? [], array_flip($ids));
    }

    public function replace(string $type, int $id, array $fields): void
    {
        foreach ($fields as $field => $locales) {
            $this->rows[$type][$id][$field] = array_filter($locales, static fn(string $value): bool => $value !== '');
            if ($this->rows[$type][$id][$field] === []) {
                unset($this->rows[$type][$id][$field]);
            }
        }
        if (($this->rows[$type][$id] ?? []) === []) {
            unset($this->rows[$type][$id]);
        }
    }

    public function forget(string $type, array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->rows[$type][$id]);
        }
    }
}
