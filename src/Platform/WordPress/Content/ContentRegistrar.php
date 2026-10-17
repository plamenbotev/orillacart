<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Content;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\PostTypeDefinition;
use OrillaCart\Shared\WordPress\TaxonomyDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registers the shop's post types and taxonomies on init.
 */
final readonly class ContentRegistrar implements Registrar
{
    /**
     * @param iterable<PostTypeDefinition> $postTypes
     * @param iterable<TaxonomyDefinition> $taxonomies
     */
    public function __construct(
        #[AutowireIterator(PostTypeDefinition::TAG)]
        private iterable $postTypes,
        #[AutowireIterator(TaxonomyDefinition::TAG)]
        private iterable $taxonomies,
    ) {}

    public function register(): void
    {
        add_action('init', $this->registerContent(...));
    }

    /** Also called outside init, by RewriteRules. */
    public function registerContent(): void
    {
        foreach ($this->postTypes as $postType) {
            if (!post_type_exists($postType->name())) {
                register_post_type($postType->name(), $postType->arguments());
            }
        }
        foreach ($this->taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy->name())) {
                register_taxonomy($taxonomy->name(), $taxonomy->objectTypes(), $taxonomy->arguments());
            }
        }
    }

    public function unregisterPostTypes(): void
    {
        foreach ($this->postTypes as $postType) {
            unregister_post_type($postType->name());
        }
    }
}
