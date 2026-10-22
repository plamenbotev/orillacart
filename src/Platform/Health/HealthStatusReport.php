<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Health;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Settings\Application\StatusReport;

/**
 * The Status screen's report: the shop's checks (ShopHealth), then what the shop, WordPress and the server are,
 * as a support request asks for them. Nothing secret: no keys, passwords or customers' details.
 */
final readonly class HealthStatusReport implements StatusReport
{
    public function __construct(
        private ShopHealth $health,
        private PluginContext $context,
    ) {}

    public function report(): array
    {
        $labels = $this->health->labels();
        $checks = [];
        foreach ($this->health->checkAll() as $id => $status) {
            $checks[] = [
                'id' => $id,
                'check' => $labels[$id] ?? $id,
                'status' => $status->status,
                'finding' => $status->label,
                'description' => $status->description,
                'action_url' => $status->actionUrl,
                'action_label' => $status->actionLabel,
            ];
        }

        return [
            'checks' => $checks,
            'sections' => [
                ['title' => __('The shop', 'orillacart'), 'fields' => [
                    ['label' => __('OrillaCart version', 'orillacart'), 'value' => $this->context->version],
                    ...$this->counts(),
                    ...array_values($this->health->info()),
                ]],
                ['title' => __('WordPress', 'orillacart'), 'fields' => self::wordpress()],
                ['title' => __('Server', 'orillacart'), 'fields' => self::server()],
            ],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function counts(): array
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $products = wp_count_posts(ProductPostType::NAME);
        $count = static fn(string $table): string => (string) (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_{$table}");

        return [
            ['label' => __('Published products', 'orillacart'), 'value' => isset($products->publish) && is_numeric($products->publish) ? (string) $products->publish : '0'],
            ['label' => __('Orders', 'orillacart'), 'value' => $count('orders')],
            ['label' => __('Customer accounts', 'orillacart'), 'value' => (string) new \WP_User_Query(['role__in' => [WordPressAccounts::ROLE], 'fields' => 'ID', 'number' => 1, 'count_total' => true])->get_total()],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function wordpress(): array
    {
        $theme = wp_get_theme();
        $plugins = array_values(array_filter((array) get_option('active_plugins', []), \is_string(...)));
        $yes = __('Yes', 'orillacart');
        $no = __('No', 'orillacart');

        return [
            ['label' => __('Version', 'orillacart'), 'value' => get_bloginfo('version')],
            ['label' => __('Site address', 'orillacart'), 'value' => home_url()],
            ['label' => __('Multisite', 'orillacart'), 'value' => is_multisite() ? $yes : $no],
            ['label' => __('Language', 'orillacart'), 'value' => get_locale()],
            ['label' => __('Time zone', 'orillacart'), 'value' => wp_timezone_string()],
            ['label' => __('Theme', 'orillacart'), 'value' => \sprintf('%s %s%s', (string) $theme->get('Name'), (string) $theme->get('Version'), wp_is_block_theme() ? ' (block theme)' : '')],
            ['label' => __('Active plugins', 'orillacart'), 'value' => implode(', ', array_map(static fn(string $plugin): string => \dirname($plugin) === '.' ? $plugin : \dirname($plugin), $plugins))],
            ['label' => __('Object cache', 'orillacart'), 'value' => wp_using_ext_object_cache() ? $yes : $no],
            ['label' => __('WP-Cron off (DISABLE_WP_CRON)', 'orillacart'), 'value' => \defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? $yes : $no],
            ['label' => __('Debug mode (WP_DEBUG)', 'orillacart'), 'value' => \defined('WP_DEBUG') && WP_DEBUG ? $yes : $no],
            ['label' => __('Memory limit (WP_MEMORY_LIMIT)', 'orillacart'), 'value' => \defined('WP_MEMORY_LIMIT') && \is_string(WP_MEMORY_LIMIT) ? WP_MEMORY_LIMIT : ''],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function server(): array
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);

        return [
            ['label' => __('PHP', 'orillacart'), 'value' => PHP_VERSION],
            ['label' => __('Database', 'orillacart'), 'value' => (string) $wpdb->db_server_info()],
            ['label' => __('Web server', 'orillacart'), 'value' => \is_string($_SERVER['SERVER_SOFTWARE'] ?? null) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : ''],
            ['label' => __('PHP memory limit', 'orillacart'), 'value' => (string) \ini_get('memory_limit')],
            ['label' => __('PHP time limit', 'orillacart'), 'value' => (string) \ini_get('max_execution_time')],
            ['label' => __('Largest upload', 'orillacart'), 'value' => (string) size_format(wp_max_upload_size())],
            ['label' => __('PHP extensions the shop uses', 'orillacart'), 'value' => implode(', ', array_map(static fn(string $extension): string => $extension . (\extension_loaded($extension) ? '' : ' (missing)'), ['mbstring', 'dom', 'simplexml', 'gd', 'curl', 'fileinfo', 'intl']))],
        ];
    }
}
