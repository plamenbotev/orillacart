<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\WordPress\AsHook;

/**
 * The shop's blocks previewed in the editor in the language of the page edited (design 51). The editor renders
 * them through WordPress's block renderer, whose request carries the post's id but none of Polylang's or WPML's
 * language: the Products block would list, and name, the products in the default one.
 */
#[AsHook('rest_dispatch_request', method: 'dispatch', filter: true, arguments: 4)]
final readonly class BlockPreviewLanguage
{
    private const string ROUTE = '/wp/v2/block-renderer/orillacart/';

    public function __construct(private RequestLanguage $language) {}

    /**
     * @param array<string, mixed> $handler
     */
    public function dispatch(mixed $result, \WP_REST_Request $request, string $route, array $handler): mixed
    {
        $postId = $request->get_param('post_id');
        $callback = $handler['callback'] ?? null;
        if ($result !== null || !str_starts_with($request->get_route(), self::ROUTE) || !is_numeric($postId) || !\is_callable($callback)) {
            return $result;
        }

        return $this->language->ofPost((int) $postId, static fn(): mixed => $callback($request));
    }
}
