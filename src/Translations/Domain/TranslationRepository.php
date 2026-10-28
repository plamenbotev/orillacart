<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Domain;

interface TranslationRepository
{
    /**
     * @param list<int> $ids
     *
     * @return array<int, string> the field's value in the locale, by object id, for those translated
     */
    public function values(string $type, array $ids, string $field, string $locale): array;

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, array<string, string>>> by object id, field and locale
     */
    public function all(string $type, array $ids): array;

    /**
     * Replaces the object's translations of these fields; an empty value removes one.
     *
     * @param array<string, array<string, string>> $fields by field and locale
     */
    public function replace(string $type, int $id, array $fields): void;

    /**
     * @param list<int> $ids objects that are gone
     */
    public function forget(string $type, array $ids): void;
}
