<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application\Dto;

/**
 * What WordPress keeps of a product: its status, texts and terms.
 */
final readonly class ProductPost
{
    /**
    * @param string       $status     publish, draft, pending or private
    * @param list<string> $categories each as its path: "Lighting > Desk lamps"
    * @param list<string> $brands
    * @param list<string> $tags
     */
    public function __construct(
        public string $status,
        public string $description,
        public string $shortDescription,
        public array $categories,
        public array $brands,
        public array $tags,
    ) {}
}
