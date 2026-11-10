<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A product's page shows the shop's reviews, so not the theme's comments too: classic themes get an empty
 * comments template, block themes no comments blocks. Comments on products come only as reviews, through the
 * shop's form, or as answers to them from someone who speaks for the shop (they moderate comments or manage the
 * shop); neither the theme's form nor WordPress's own API (/wp/v2/comments, which saves without asking the
 * theme's form's filters) gets round the shop's policy. An answer is marked as the shop's as it is written, so
 * it stays one after its writer left.
 */
#[AsHook('comments_template', method: 'template', filter: true, priority: 20)]
#[AsHook('render_block', method: 'block', filter: true, arguments: 2)]
#[AsHook('preprocess_comment', method: 'comment', filter: true)]
#[AsHook('rest_pre_insert_comment', method: 'restComment', filter: true)]
#[AsHook('wp_insert_comment', method: 'written', arguments: 2)]
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
     * A comment from the theme's form, the Comments screen or XML-RPC.
     *
     * @param array<string, mixed> $comment
     *
     * @return array<string, mixed>
     */
    public function comment(array $comment): array
    {
        if (self::taken($comment)) {
            return $comment;
        }
        wp_die(esc_html__('Review the product from its page.', 'orillacart'), esc_html__('Comment not accepted', 'orillacart'), ['response' => 403, 'back_link' => true]);
    }

    /**
     * A comment sent to WordPress's own API, which saves it without the filter above.
     *
     * @param array<string, mixed>|\WP_Error $comment
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function restComment(array|\WP_Error $comment): array|\WP_Error
    {
        if ($comment instanceof \WP_Error || self::taken($comment)) {
            return $comment;
        }

        return new \WP_Error('orillacart_review_from_page', __('Review the product from its page.', 'orillacart'), ['status' => 403]);
    }

    /**
     * An answer to a review, as it is saved by whoever is logged in: the shop's when they speak for it.
     */
    public function written(int|string $id, mixed $comment): void
    {
        if (!$comment instanceof \WP_Comment || (int) $comment->comment_parent <= 0 || $comment->comment_type === CommentReviewStore::TYPE || get_post_type((int) $comment->comment_post_ID) !== ProductPostType::NAME) {
            return;
        }
        $writer = (int) $comment->user_id;
        if ($writer > 0 && $writer === get_current_user_id() && CommentReviewStore::speaksForTheShop($writer)) {
            add_comment_meta((int) $id, CommentReviewStore::SHOPS_ANSWER, '1', true);
        }
    }

    /**
     * Whether the comment may be saved: any on something else than a product, a review as the shop's own form
     * writes it, and an answer to a comment from someone who speaks for the shop.
     *
     * @param array<string, mixed> $comment
     */
    private static function taken(array $comment): bool
    {
        $postId = $comment['comment_post_ID'] ?? 0;
        if (!is_numeric($postId) || get_post_type((int) $postId) !== ProductPostType::NAME || ($comment['comment_type'] ?? '') === CommentReviewStore::TYPE) {
            return true;
        }
        $parent = $comment['comment_parent'] ?? 0;

        return is_numeric($parent) && (int) $parent > 0 && CommentReviewStore::speaksForTheShop(get_current_user_id());
    }
}
