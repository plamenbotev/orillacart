<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * What shop managers see of the shop at a glance: the dashboard's Shop box and the admin bar's orders.
 */
final class AdminGlanceTest extends RestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_transient('orillacart_orders_to_do');
    }

    public function testTheDashboardShowsSalesAndWhatWaits(): void
    {
        require_once ABSPATH . 'wp-admin/includes/dashboard.php';
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        set_current_screen('dashboard');
        $this->actAs('administrator');
        do_action('wp_dashboard_setup');

        global $wp_meta_boxes;
        self::assertIsArray($wp_meta_boxes);
        $widget = self::path($wp_meta_boxes, ['dashboard', 'normal', 'core', 'orillacart_shop']);
        self::assertIsArray($widget, 'the Shop box is on the dashboard');
        self::assertIsCallable($widget['callback']);
        ob_start();
        ($widget['callback'])();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Last 7 days', $html);
        self::assertMatchesRegularExpression('#page=orillacart-orders&amp;status=processing">\d+ orders? to ship#', $html);
        self::assertStringContainsString('low in stock', $html);
    }

    /**
     * @param array<mixed> $array
     * @param list<string> $keys
     */
    private static function path(array $array, array $keys): mixed
    {
        $value = $array;
        foreach ($keys as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    public function testTheAdminBarCountsTheOrdersThatWaitForShopManagersOnly(): void
    {
        require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
        $bar = new \WP_Admin_Bar();
        do_action('admin_bar_menu', $bar);
        self::assertNull($bar->get_node('orillacart-orders'), 'not for a visitor');

        $this->actAs('administrator');
        $bar = new \WP_Admin_Bar();
        do_action('admin_bar_menu', $bar);
        $node = $bar->get_node('orillacart-orders');
        self::assertNotNull($node);
        self::assertStringContainsString('page=orillacart-orders', self::property($node, 'href'));
        self::assertNotNull($bar->get_node('orillacart-orders-ship'));
        $attention = $bar->get_node('orillacart-orders-attention');
        self::assertNotNull($attention);
        self::assertMatchesRegularExpression('/^\d+ needs? attention$/', self::property($attention, 'title'));
    }

    private static function property(object $node, string $name): string
    {
        $value = get_object_vars($node)[$name] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
