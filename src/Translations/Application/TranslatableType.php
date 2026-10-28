<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

/**
 * Something of an area's own tables whose words the shop translates (design 51): attributes, options, shipping
 * methods. Each area says which of its fields, how long each may be, and which of its objects exist.
 */
interface TranslatableType
{
    public const string TAG = 'orillacart.translatable_type';

    /** The type's name in the translations table and the REST API, such as "attribute". */
    public function type(): string;

    /**
     * @return array<string, int> the longest value of each translated field, by field
     */
    public function fields(): array;

    /**
     * @param list<int> $ids
     *
     * @return list<int> those that exist
     */
    public function existing(array $ids): array;
}
