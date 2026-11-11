<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** The shop's attribute sets (API 26): attributes products offer by being in the set. */
interface AttributeSets
{
    /** @return list<AttributeSetSummary> */
    public function all(): array;

    /**
     * Makes a set, or replaces one's name and attributes (null id: a new one). Attributes and options named as
     * the set has them keep their ids.
     *
     * @param list<AttributeDraft> $attributes
     *
     * @throws \InvalidArgumentException for no such set, or a name or option the shop cannot take
     */
    public function save(?int $id, string $name, array $attributes, bool $active = true): AttributeSetSummary;
}
