<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Infrastructure\WordPress\RunJobs;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Settings\Infrastructure\WordPress\OptionSettingsRepository;
use OrillaCart\Shared\Files\StorageCheck;
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
        LocalPrivateFiles::mark($folder);
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

    public function testEachInstallAndSiteKeepsItsFilesInAFolderOfItsOwn(): void
    {
        $shared = sys_get_temp_dir() . '/orillacart-' . bin2hex(random_bytes(4)) . '/orillacart-downloads';
        $ours = LocalPrivateFiles::siteFolder($shared, ABSPATH, get_current_blog_id());
        self::assertNotSame($ours, LocalPrivateFiles::siteFolder($shared, '/home/shop/staging/', get_current_blog_id()), 'another install beside this one');
        self::assertNotSame($ours, LocalPrivateFiles::siteFolder($shared, ABSPATH, get_current_blog_id() + 1), 'another site of the network');

        $other = LocalPrivateFiles::siteFolder($shared, '/home/shop/staging/', 1);
        wp_mkdir_p($ours);
        wp_mkdir_p($other);
        $key = bin2hex(random_bytes(16));
        file_put_contents("{$ours}/{$key}", 'our guide');
        file_put_contents("{$other}/{$key}", 'their guide');
        $previous = [get_option(LocalPrivateFiles::DIRECTORY_OPTION), get_option(LocalPrivateFiles::CREATED_OPTION)];
        update_option(LocalPrivateFiles::DIRECTORY_OPTION, $ours);
        update_option(LocalPrivateFiles::CREATED_OPTION, $ours);
        LocalPrivateFiles::mark($ours);
        try {
            new LocalPrivateFiles()->uninstall();

            self::assertDirectoryDoesNotExist($ours);
            self::assertFileExists("{$other}/{$key}", 'removing this shop\'s data leaves theirs, though their file has a name like ours');
        } finally {
            @unlink("{$other}/{$key}");
            @rmdir($other);
            @rmdir($shared);
            @rmdir(\dirname($shared));
            foreach ([LocalPrivateFiles::DIRECTORY_OPTION, LocalPrivateFiles::CREATED_OPTION] as $index => $option) {
                $previous[$index] === false ? delete_option($option) : update_option($option, $previous[$index]);
            }
        }
    }

    public function testAMovedSiteAdoptsItsFolderOrACopyStartsANewOne(): void
    {
        $moved = sys_get_temp_dir() . '/orillacart-moved-' . bin2hex(random_bytes(4));
        mkdir($moved);
        $key = bin2hex(random_bytes(16));
        file_put_contents("{$moved}/{$key}", 'the guide');
        file_put_contents("{$moved}/" . LocalPrivateFiles::MARKER, 'site ' . get_current_blog_id() . " of 0123456789abcdef\n");
        $previous = [get_option(LocalPrivateFiles::DIRECTORY_OPTION), get_option(LocalPrivateFiles::CREATED_OPTION)];
        update_option(LocalPrivateFiles::DIRECTORY_OPTION, $moved);
        delete_option(LocalPrivateFiles::CREATED_OPTION);
        try {
            self::assertNull(new LocalPrivateFiles()->path($key));
            $adopted = new LocalPrivateFiles()->answer(StorageCheck::ADOPT);
            self::assertNull($adopted->claim);
            self::assertSame("{$moved}/{$key}", new LocalPrivateFiles()->path($key), 'moved: served from it again');

            file_put_contents("{$moved}/" . LocalPrivateFiles::MARKER, "site 7 of 0123456789abcdef\n");
            $new = new LocalPrivateFiles()->answer(StorageCheck::START_NEW);
            self::assertNull($new->claim);
            self::assertNotSame($moved, get_option(LocalPrivateFiles::DIRECTORY_OPTION), 'a copy: a folder of its own');
            self::assertFileExists("{$moved}/{$key}", 'the other\'s files left alone');
        } finally {
            $left = glob("{$moved}/{,.}*[!.]*", GLOB_BRACE);
            array_map(unlink(...), $left === false ? [] : $left);
            @rmdir($moved);
            foreach ([LocalPrivateFiles::DIRECTORY_OPTION, LocalPrivateFiles::CREATED_OPTION] as $index => $option) {
                $previous[$index] === false ? delete_option($option) : update_option($option, $previous[$index]);
            }
        }
    }

    public function testAClonedSiteNeitherUsesNorRemovesTheOriginalsFolder(): void
    {
        $original = sys_get_temp_dir() . '/orillacart-original-' . bin2hex(random_bytes(4));
        mkdir($original);
        $key = bin2hex(random_bytes(16));
        file_put_contents("{$original}/{$key}", 'the original\'s guide');
        // The original wrote its marker; the clone's options came with the copy of its database.
        file_put_contents("{$original}/" . LocalPrivateFiles::MARKER, 'site 7 of 0123456789abcdef' . "\n");
        $previous = [get_option(LocalPrivateFiles::DIRECTORY_OPTION), get_option(LocalPrivateFiles::CREATED_OPTION)];
        update_option(LocalPrivateFiles::DIRECTORY_OPTION, $original);
        update_option(LocalPrivateFiles::CREATED_OPTION, $original);
        try {
            self::assertNull(new LocalPrivateFiles()->path($key), 'the clone does not read the original\'s files');
            self::assertSame($original, get_option(LocalPrivateFiles::DIRECTORY_OPTION), 'nor forgets where they are: it asks');
            $check = new LocalPrivateFiles()->check();
            self::assertSame([StorageCheck::ELSEWHERE, $original], [$check->claim, $check->directory]);
            new LocalPrivateFiles()->delete($key);
            self::assertFileExists("{$original}/{$key}", 'deleting nothing while it asks');

            new LocalPrivateFiles()->uninstall();

            self::assertFileExists("{$original}/{$key}", 'removing the clone\'s data leaves the original\'s');
            self::assertSame("site 7 of 0123456789abcdef\n", file_get_contents("{$original}/" . LocalPrivateFiles::MARKER));
        } finally {
            $left = glob("{$original}/{,.}*[!.]*", GLOB_BRACE);
            array_map(unlink(...), $left === false ? [] : $left);
            @rmdir($original);
            foreach ([LocalPrivateFiles::DIRECTORY_OPTION, LocalPrivateFiles::CREATED_OPTION] as $index => $option) {
                $previous[$index] === false ? delete_option($option) : update_option($option, $previous[$index]);
            }
        }
    }
}
