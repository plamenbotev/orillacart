<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Settings\Application\SampleData;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Adds a few sample products to see the shop working, and takes them away again.
 */
final readonly class SampleDataCommand implements CliCommand
{
    public function __construct(private SampleData $samples) {}

    public static function name(): string
    {
        return 'orillacart sample-data';
    }

    /**
     * Adds eight sample products in categories and brands, with sizes, colours, stock, sales and pictures, and
     * the Size and Colour filters. Then build the search index: `wp orillacart jobs work`.
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function add(array $args, array $options): void
    {
        $this->asShopManager();
        $added = $this->samples->add();
        \WP_CLI::success($added === 0 ? 'The sample products are there already.' : "Added {$added} sample products; `wp orillacart jobs work` builds the search index for them.");
    }

    /**
     * Takes the sample products away, with the pictures, categories, brands and filters they brought.
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function remove(array $args, array $options): void
    {
        $this->asShopManager();
        \WP_CLI::success(\sprintf('Removed %d sample products.', $this->samples->remove()));
    }

    /** The shop's API takes them from a shop manager: the first administrator. */
    private function asShopManager(): void
    {
        if (get_current_user_id() === 0) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            wp_set_current_user(is_numeric($admins[0] ?? null) ? (int) $admins[0] : 1);
        }
    }
}
