<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Jobs\Application\JobSites;
use OrillaCart\Jobs\Application\JobWorkers;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\Upgrades\NetworkUpgrades;
use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;

/**
 * The network's shops for a worker: each site in a container of its own (SiteKernels), so its jobs run on its
 * tables, in its language and with its settings. A shop whose tables are behind the files' build waits until the
 * network's walk (its chores) or its own first visit brings it up: the new code does not run on old tables.
 *
 * The worker runs with the plugins and theme of the site it booted as, taken when it first looks (loaded()): what
 * the process loaded, not what the site's options say later. A shop with other plugins of its own (an add-on that
 * syncs orders with an ERP, a gateway) or another theme (its email templates, its functions.php) is not served:
 * its jobs would run without its own and with the booting site's. It needs a worker of its own (`--url`); add-ons
 * on for the whole network are loaded for every site. When the booting site's plugins or theme change, or those
 * of the whole network, the worker is outdated and stops, for its supervisor to start it on what is on now.
 */
final class NetworkJobSites implements JobSites
{
    private const int CHORE_SECONDS = 10;

    /** @var array{site: int, plugins: list<string>, network: list<string>, theme: string}|null what this process loaded */
    private ?array $loaded = null;

    public function __construct(
        private readonly SiteUpgrades $upgrades,
        private readonly NetworkUpgrades $network,
        private readonly PluginContext $context,
    ) {}

    public function network(): bool
    {
        return is_multisite();
    }

    public function sites(): array
    {
        $apart = $this->apart();

        return array_values(array_filter($this->current(), static fn(int $site): bool => !isset($apart[$site])));
    }

    public function apart(): array
    {
        $loaded = $this->loaded();
        $apart = [];
        foreach ($this->current() as $site) {
            $why = self::apartFor($loaded, $this->upgrades->plugins($site), self::theme($site));
            if ($why !== null) {
                $apart[$site] = $why;
            }
        }

        return $apart;
    }

    /**
     * Why a site is left out by a worker that loaded $loaded: PLUGINS, THEME, or null when it serves it.
     *
     * @param array{plugins: list<string>, theme: string} $loaded
     * @param list<string>                                 $plugins the site's own, sorted
     */
    public static function apartFor(array $loaded, array $plugins, string $theme): ?string
    {
        return match (true) {
            $plugins !== $loaded['plugins'] => self::PLUGINS,
            $theme !== $loaded['theme'] => self::THEME,
            default => null,
        };
    }

    /** @return list<int> the shops at the files' build */
    private function current(): array
    {
        return array_keys(array_filter($this->upgrades->shops(), fn(string $stored): bool => $stored === $this->context->build));
    }

    public function outdated(): bool
    {
        if ($this->context->filesBuild() !== $this->context->build) {
            return true;
        }
        $loaded = $this->loaded();

        return self::changed($loaded, $this->upgrades->plugins($loaded['site']), self::networkPlugins(), self::theme($loaded['site']));
    }

    /**
     * Whether what the booting site would load now is not what the process loaded.
     *
     * @param array{plugins: list<string>, network: list<string>, theme: string} $loaded
     * @param list<string>                                                       $plugins the booting site's own now
     * @param list<string>                                                       $network the network's now
     */
    public static function changed(array $loaded, array $plugins, array $network, string $theme): bool
    {
        return $plugins !== $loaded['plugins'] || $network !== $loaded['network'] || $theme !== $loaded['theme'];
    }

    /** @return array{site: int, plugins: list<string>, network: list<string>, theme: string} */
    private function loaded(): array
    {
        if ($this->loaded === null) {
            $site = get_current_blog_id();
            $this->loaded = ['site' => $site, 'plugins' => $this->upgrades->plugins($site), 'network' => self::networkPlugins(), 'theme' => self::theme($site)];
        }

        return $this->loaded;
    }

    /** @return list<string> the plugins on for the whole network, sorted */
    private static function networkPlugins(): array
    {
        $network = get_site_option('active_sitewide_plugins');
        $plugins = \is_array($network) ? array_map(strval(...), array_keys($network)) : [];
        sort($plugins);

        return $plugins;
    }

    /** The site's theme and the theme it is a child of, as the site's options say. */
    private static function theme(int $site): string
    {
        $stylesheet = get_blog_option($site, 'stylesheet');
        $template = get_blog_option($site, 'template');

        return (\is_string($stylesheet) ? $stylesheet : '') . '/' . (\is_string($template) ? $template : '');
    }

    public function chores(): void
    {
        // A batch of the network's walk after an update, short so the queues are not kept waiting.
        $this->network->start();
        $this->network->step(self::CHORE_SECONDS);
    }

    public function on(int $site, \Closure $work): mixed
    {
        return SiteKernels::run($site, $this->context, static function (Kernel $kernel) use ($work): mixed {
            $runner = $kernel->container()->get(JobRunner::class);
            $workers = $kernel->container()->get(JobWorkers::class);
            \assert($runner instanceof JobRunner && $workers instanceof JobWorkers);

            return $work($runner, $workers);
        });
    }
}
