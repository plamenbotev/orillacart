<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

/**
 * Something of an area's own tables whose words the shop translates (design 51): attributes, options, shipping
 * methods. Each area says which of its fields, and how long each may be.
 */
interface TranslatableType
{
    public const string TAG = 'orillacart.translatable_type';

    /** The type's name in the translations table and the REST API, such as "attribute". */
    public function type(): string;

    /**
     * A field of something the object holds in a list has its item's key in braces: "pickup.{key}.name" for each of
     * a carrier's pickup places, "pickup.front-desk.name" in the table.
     *
     * @return array<string, int> the longest value of each translated field, by field
     */
    public function fields(): array;
}
