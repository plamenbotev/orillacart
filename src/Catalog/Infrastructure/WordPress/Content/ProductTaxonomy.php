<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Content;

use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\TaxonomyDefinition;

/**
 * Shared settings of the taxonomies that sort products.
 */
abstract readonly class ProductTaxonomy implements TaxonomyDefinition
{
    public function objectTypes(): array
    {
        return [ProductPostType::NAME];
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array{
     *   labels: array<string, string>,
     *   hierarchical: bool,
     *   public: bool,
     *   show_in_rest: bool,
     *   show_admin_column: bool,
     *   rewrite: array{slug: string, with_front: bool, hierarchical: bool},
     *   capabilities: array{manage_terms: string, edit_terms: string, delete_terms: string, assign_terms: string},
     * }
     */
    protected static function common(array $labels, string $slug, bool $hierarchical): array
    {
        return [
            'labels' => $labels,
            'hierarchical' => $hierarchical,
            'public' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => $slug, 'with_front' => false, 'hierarchical' => $hierarchical],
            'capabilities' => [
                'manage_terms' => Capability::MANAGE_SHOP,
                'edit_terms' => Capability::MANAGE_SHOP,
                'delete_terms' => Capability::MANAGE_SHOP,
                'assign_terms' => Capability::MANAGE_SHOP,
            ],
        ];
    }
}
