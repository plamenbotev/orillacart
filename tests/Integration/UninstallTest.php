<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Infrastructure\WordPress\RunJobs;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Security\Capability;
use PHPUnit\Framework\TestCase;

/**
 * Deleting the plugin, on the wp-env site: its data stays unless the shop asked for it to go.
 */
final class UninstallTest extends TestCase
{
    private PluginLifecycle $lifecycle;

    protected function setUp(): void
    {
        $lifecycle = new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->container()->get(PluginLifecycle::class);
        self::assertInstanceOf(PluginLifecycle::class, $lifecycle);
        $this->lifecycle = $lifecycle;
    }

    protected function tearDown(): void
    {
        // Everything back as activation leaves it, for the other tests.
        $this->lifecycle->activate();
    }

    public function testDeletingThePluginKeepsTheShopsDataAndRemovesWhatItCanMakeAgain(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        $settings = get_option(OptionSettingsRepository::OPTION);
        self::assertNotFalse(wp_next_scheduled(RunJobs::hook()), 'scheduled while the plugin is on');

        $this->lifecycle->uninstall();

        self::assertSame($wpdb->prefix . 'orillacart_orders', $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'orillacart_orders')), 'the orders stay');
        self::assertSame($settings, get_option(OptionSettingsRepository::OPTION), 'and the settings');
        self::assertFalse(wp_next_scheduled(RunJobs::hook()), 'its schedules go');
        $admin = get_role('administrator');
        self::assertInstanceOf(\WP_Role::class, $admin);
        self::assertFalse($admin->has_cap(Capability::MANAGE_SHOP), 'and its capabilities');
    }

    public function testTheDownloadsFolderLosesOnlyWhatThePluginWrote(): void
    {
        $folder = sys_get_temp_dir() . '/orillacart-shared-' . bin2hex(random_bytes(4));
        mkdir($folder);
        $ours = bin2hex(random_bytes(16));
        file_put_contents("{$folder}/{$ours}", 'a guide');
        file_put_contents("{$folder}/.htaccess", 'deny');
        file_put_contents("{$folder}/someone-elses.pdf", 'kept');
        $previous = get_option(LocalPrivateFiles::DIRECTORY_OPTION);
        update_option(LocalPrivateFiles::DIRECTORY_OPTION, $folder);
        try {
            new LocalPrivateFiles()->uninstall();

            self::assertSame(['someone-elses.pdf'], array_values(array_diff((array) scandir($folder), ['.', '..'])), 'a folder the plugin found stays, with what is not its own');
        } finally {
            $left = glob("{$folder}/*");
            array_map(unlink(...), $left === false ? [] : $left);
            rmdir($folder);
            $previous === false ? delete_option(LocalPrivateFiles::DIRECTORY_OPTION) : update_option(LocalPrivateFiles::DIRECTORY_OPTION, $previous);
        }
    }
}
