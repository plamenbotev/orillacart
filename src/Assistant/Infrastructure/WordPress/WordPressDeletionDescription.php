<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Infrastructure\Descriptions\Words;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;

/**
 * A product trashed or a category deleted, as WordPress keeps them: by name, with what goes with them.
 */
final readonly class WordPressDeletionDescription implements CallDescription
{
    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null) {
            return null;
        }
        if ($tool->name === 'trash_product') {
            $post = get_post($id);

            return $post instanceof \WP_Post && $post->post_type === ProductPostType::NAME
                ? "Moves the product \"{$post->post_title}\" to the trash: it is no longer sold or shown, and placed orders keep it. It can be brought back as a draft."
                : null;
        }
        if ($tool->name === 'delete_category') {
            $term = get_term($id, ProductCategoryTaxonomy::NAME);

            return $term instanceof \WP_Term
                ? "Deletes the category \"{$term->name}\" for good. Its {$term->count} products stay, in their other categories; on a site in several languages it goes in every language."
                : null;
        }

        return null;
    }
}
