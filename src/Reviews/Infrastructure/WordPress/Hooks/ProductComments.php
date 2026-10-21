<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A product's page shows the shop's reviews, so not the theme's comments too: classic themes get an empty
 * comments template, block themes no comments blocks. Comments on products come only as reviews, through the
 * shop's form, or as answers to them from someone who moderates comments; the theme's form cannot get round
 * the shop's policy.
 */
#[AsHook('comments_template', method: 'template', filter: true, priority: 20)]
#[AsHook('render_block', method: 'block', filter: true, arguments: 2)]
#[AsHook('preprocess_comment', method: 'comment', filter: true)]
final readonly class ProductComments
{
    private const array COMMENT_BLOCKS = ['core/comments', 'core/post-comments-form', 'core/post-comments'];

    public function template(string $template): string
    {
        return is_singular(ProductPostType::NAME) ? \dirname(__DIR__, 5) . '/templates/wordpress/no-comments.php' : $template;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function block(string $html, array $block): string
    {
        return \in_array($block['blockName'] ?? null, self::COMMENT_BLOCKS, true) && is_singular(ProductPostType::NAME) ? '' : $html;
    }

    /**
     * @param array<string, mixed> $comment
     *
     * @return array<string, mixed>
     */
    public function comment(array $comment): array
    {
        $postId = $comment['comment_post_ID'] ?? 0;
        if (!is_numeric($postId) || get_post_type((int) $postId) !== ProductPostType::NAME || ($comment['comment_type'] ?? '') === CommentReviewStore::TYPE) {
            return $comment;
        }
        $parent = $comment['comment_parent'] ?? 0;
        if (is_numeric($parent) && (int) $parent > 0 && current_user_can('moderate_comments')) {
            return $comment;
        }
        wp_die(esc_html__('Review the product from its page.', 'orillacart'), esc_html__('Comment not accepted', 'orillacart'), ['response' => 403, 'back_link' => true]);
    }
}
