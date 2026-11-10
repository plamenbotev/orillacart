<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\WordPress\Hooks;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Products saved and deleted through WordPress's own API (/wp/v2/orillacart_product, as the block editor saves
 * them) go into the change log, as the shop's routes do: the title, status, categories and the rest as sent. A long
 * text (the description) is kept as its length. The log never stands in the change's way.
 */
#[AsHook('rest_after_insert_' . ProductPostType::NAME, method: 'saved', arguments: 3)]
#[AsHook('rest_delete_' . ProductPostType::NAME, method: 'deleted', arguments: 3)]
final readonly class RecordProductPostSaves
{
    private const string ROUTE = '/wp/v2/' . ProductPostType::NAME . '/{id}';
    private const int LONGEST = 300;

    public function __construct(
        private ChangeLog $log,
        private LoggerInterface $logger,
    ) {}

    public function saved(mixed $post, mixed $request, mixed $creating): void
    {
        if ($post instanceof \WP_Post && $request instanceof \WP_REST_Request) {
            $this->record($request->get_method(), $post->ID, $request->get_json_params() ?? $request->get_body_params());
        }
    }

    public function deleted(mixed $post, mixed $response, mixed $request): void
    {
        if ($post instanceof \WP_Post && $request instanceof \WP_REST_Request) {
            $this->record('DELETE', $post->ID, ['force' => $request->get_param('force') ?? false]);
        }
    }

    /**
     * @param array<array-key, mixed>|null $values
     */
    private function record(string $method, int $id, ?array $values): void
    {
        try {
            // How the request asked to be answered is not what it changed.
            $this->log->record(get_current_user_id(), $method, self::ROUTE, '/wp/v2/' . ProductPostType::NAME . '/' . $id, self::shortened(array_diff_key($values ?? [], ['context' => true, '_locale' => true, '_fields' => true, '_embed' => true])));
        } catch (\Throwable $e) {
            $this->logger->error('The change log could not record {route}: {message}', ['route' => "{$method} " . self::ROUTE, 'message' => $e->getMessage()]);
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
