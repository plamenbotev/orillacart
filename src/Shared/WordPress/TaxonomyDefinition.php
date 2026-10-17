<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A taxonomy the shop registers, after the post types. Put the class in the owning area's
 * Infrastructure/WordPress/Content folder.
 */
#[AutoconfigureTag(self::TAG)]
interface TaxonomyDefinition
{
    public const string TAG = 'orillacart.taxonomy';

    /**
     * At most 32 characters.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /** @return list<string> the post types it classifies */
    public function objectTypes(): array;

    /**
     * Arguments for register_taxonomy(); the keys the shop's taxonomies use.
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
    public function arguments(): array;
}
