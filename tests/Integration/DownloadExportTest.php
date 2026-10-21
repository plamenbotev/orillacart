<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The orders export reads the Orders screen's filters from the address; wrong ones say what is wrong.
 */
final class DownloadExportTest extends RestTestCase
{
    public function testAWrongFilterSaysWhatIsWrong(): void
    {
        $this->actAs('administrator');
        $_GET = ['action' => 'orillacart_export_orders', 'status' => 'nonsense', '_wpnonce' => wp_create_nonce('wp_rest')];
        /** @param array<string, mixed> $args */
        $died = static fn(): \Closure => static function (string $message, string $title = '', array $args = []): never {
            $response = $args['response'] ?? null;
            throw new \RuntimeException($message . ' (' . (\is_int($response) ? $response : '') . ')');
        };
        add_filter('wp_die_handler', $died);
        try {
            do_action('admin_post_orillacart_export_orders');
            self::fail('a status the shop has not was taken');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('status: ', $e->getMessage());
            self::assertStringEndsWith('(422)', $e->getMessage());
        } finally {
            remove_filter('wp_die_handler', $died);
            $_GET = [];
        }
    }
}
