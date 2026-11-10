<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\WordPress\Hooks;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Products changed through WordPress's own ways go into the change log, as the shop's routes do: saves through its
 * API (/wp/v2/orillacart_product, as the block editor saves them), the classic editing screen and the list's quick
 * edit, and a product moved to the Trash, restored from it or deleted, however that was asked for. What was sent is
 * kept, from the fields those forms have (the title, status, categories and the rest); a long text (the
 * description) is kept as its length. The log never stands in the change's way.
 */
#[AsHook('rest_after_insert_' . ProductPostType::NAME, method: 'saved', arguments: 3)]
#[AsHook('save_post_' . ProductPostType::NAME, method: 'edited', priority: 30, arguments: 3)]
#[AsHook('trashed_post', method: 'trashed')]
#[AsHook('untrashed_post', method: 'restored')]
#[AsHook('deleted_post', method: 'deleted', arguments: 2)]
final readonly class RecordProductPostSaves
{
    private const string ROUTE = '/wp/v2/' . ProductPostType::NAME . '/{id}';
    private const int LONGEST = 300;
    /** What the editing screen and quick edit send that changes the product; the rest is how the form works. */
    private const array FORM_FIELDS = [
        'post_title', 'post_name', 'post_status', 'visibility', 'post_password', 'post_author', 'comment_status',
        'ping_status', 'menu_order', 'post_parent', 'tax_input', 'post_category', 'sticky', 'aa', 'mm', 'jj', 'hh',
        'mn', 'content', 'excerpt', 'orillacart_price',
    ];
    /** Quick edit sends the description and excerpt as they were, unchanged: not something it changed. */
    private const array NOT_QUICK_EDITED = ['content', 'excerpt'];
    /** How a request to WordPress's API asks to be answered, not what it changed. */
    private const array PRESENTATION = ['id' => true, 'context' => true, '_locale' => true, '_fields' => true, '_embed' => true, '_envelope' => true, '_jsonp' => true, '_method' => true, 'rest_route' => true];

    public function __construct(
        private ChangeLog $log,
        private LoggerInterface $logger,
    ) {}

    /** A save through WordPress's API: what was sent, as JSON, as a form or in the address. */
    public function saved(mixed $post, mixed $request, mixed $creating): void
    {
        if ($post instanceof \WP_Post && $request instanceof \WP_REST_Request) {
            $sent = array_replace($request->get_query_params(), $request->get_body_params(), $request->get_json_params() ?? []);
            $this->record($request->get_method(), self::ROUTE, '/wp/v2/' . ProductPostType::NAME . '/' . $post->ID, array_diff_key($sent, self::PRESENTATION));
        }
    }

    /**
     * A save from the classic editing screen or the list's quick edit, whose requests WordPress checked (nonce
     * and capability) before saving. Other saves (the shop's own, imports, the block editor's meta boxes after its
     * API save) are recorded where they are made, or not changes of their own.
     */
    public function edited(int $postId, mixed $post, mixed $update): void
    {
        $action = $_POST['action'] ?? null;
        $edited = $_POST['post_ID'] ?? null;
        if (!is_admin() || !\in_array($action, ['editpost', 'inline-save'], true) || isset($_REQUEST['meta-box-loader']) || !is_numeric($edited) || (int) $edited !== $postId) {
            return;
        }
        $fields = $action === 'inline-save' ? array_diff(self::FORM_FIELDS, self::NOT_QUICK_EDITED) : self::FORM_FIELDS;
        $sent = array_intersect_key(wp_unslash($_POST), array_flip($fields));
        [$route, $path] = $action === 'inline-save' ? ['/products/{id}/quick-edit', "/products/{$postId}/quick-edit"] : ['/products/{id}/edit', "/products/{$postId}/edit"];
        $this->record('PUT', $route, $path, $sent);
    }

    public function trashed(int $postId): void
    {
        if (get_post_type($postId) === ProductPostType::NAME) {
            $this->record('POST', '/products/{id}/trash', "/products/{$postId}/trash", []);
        }
    }

    public function restored(int $postId): void
    {
        if (get_post_type($postId) === ProductPostType::NAME) {
            $this->record('POST', '/products/{id}/restore', "/products/{$postId}/restore", []);
        }
    }

    public function deleted(int $postId, mixed $post): void
    {
        if ($post instanceof \WP_Post && $post->post_type === ProductPostType::NAME) {
            $this->record('DELETE', '/products/{id}', "/products/{$postId}", ['title' => $post->post_title]);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function record(string $method, string $route, string $path, array $values): void
    {
        try {
            $user = get_current_user_id();
            $this->log->record($user > 0 ? $user : null, $method, $route, $path, self::shortened($values));
        } catch (\Throwable $e) {
            $this->logger->error('The change log could not record {route}: {message}', ['route' => "{$method} {$route}", 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function shortened(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value) && mb_strlen($value) > self::LONGEST) {
                $values[$key] = \sprintf('(%d characters)', mb_strlen($value));
            } elseif (\is_array($value)) {
                $values[$key] = self::shortened($value);
            }
        }

        return $values;
    }
}
