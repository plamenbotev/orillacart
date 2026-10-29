<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * A category, brand or tag a product is filed under.
 */
final readonly class DocumentTerm
{
    /**
     * @param array<string, string> $labels its name in the site's other languages, by locale, where it differs
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $labels = [],
    ) {}
}
