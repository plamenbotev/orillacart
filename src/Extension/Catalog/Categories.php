<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/** The shop's product categories (API 26). */
interface Categories
{
    /** @return list<CategorySummary> by path */
    public function all(): array;

    /**
     * The category the path names, made with its parents where missing: "Lighting > Desk lamps".
     *
     * @throws \InvalidArgumentException for an empty path
     */
    public function ensure(string $path): int;
}
