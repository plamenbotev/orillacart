<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * The orders export reads the Orders screen's filters from the address; wrong ones say what is wrong. The address
 * carries the nonce of its own action, not the REST API's.
 */
final class DownloadExportTest extends RestTestCase
{
    public function testAWrongFilterSaysWhatIsWrong(): void
    {
        $this->actAs('administrator');
        $_GET = ['action' => 'orillacart_export_orders', 'status' => 'nonsense', '_wpnonce' => wp_create_nonce('orillacart_export_orders')];
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

    public function testTheRestNonceDownloadsNothing(): void
    {
        $this->actAs('administrator');
        /** @param array<string, mixed> $args */
        $died = static fn(): \Closure => static function (string $message, string $title = '', array $args = []): never {
            $response = $args['response'] ?? null;
            throw new \RuntimeException((string) (\is_int($response) ? $response : ''));
        };
        add_filter('wp_die_handler', $died);
        try {
            foreach ([
                'admin_post_orillacart_export_orders' => ['action' => 'orillacart_export_orders'],
                'admin_post_orillacart_export_products' => ['action' => 'orillacart_export_products'],
                'admin_post_orillacart_order_upload' => ['action' => 'orillacart_order_upload', 'order' => '1', 'upload' => '1'],
                'admin_post_orillacart_exemption_file' => ['action' => 'orillacart_exemption_file', 'customer' => '1', 'exemption' => '1'],
            ] as $hook => $query) {
                foreach (['wp_rest', 'orillacart_export_subscribers'] as $other) {
                    $_GET = $query + ['_wpnonce' => wp_create_nonce($other)];
                    try {
                        do_action($hook);
                        self::fail("{$hook} took the nonce of {$other}");
                    } catch (\RuntimeException $e) {
                        self::assertSame('403', $e->getMessage(), $hook);
                    }
                }
            }
        } finally {
            remove_filter('wp_die_handler', $died);
            $_GET = [];
        }
    }
}
