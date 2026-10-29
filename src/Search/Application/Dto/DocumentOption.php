<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * An attribute option a product or variant offers, by its own names, which filters go by: "Size", "M".
 */
final readonly class DocumentOption
{
    /**
     * @param array<string, string> $labels the option's name in the site's other languages, by locale, where it differs
     */
    public function __construct(
        public string $attribute,
        public string $name,
        public array $labels = [],
    ) {}
}
