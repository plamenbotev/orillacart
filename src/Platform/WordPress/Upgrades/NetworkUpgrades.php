<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Platform\Lifecycle\PluginLifecycle;
use OrillaCart\Platform\PluginContext;

/**
 * Brings every shop of a network up to the files' build after an update, run by the network and not by any shop.
 * Not one loop over the whole network in one request: short batches, each under a lock, that go on from where the
 * last one stopped. The main site's scheduled tasks run them, and so does a worker for the network (jobs work
 * --network); each shop still upgrades on its own first request meanwhile. A site that fails is tried again later,
 * a few times, and then waits for the network admin.
 */
final readonly class NetworkUpgrades
{
    /** Network option: the NetworkWalk for the files' build. */
    public const string OPTION = 'orillacart_network_upgrade';
    /** The main site's scheduled task that runs a batch. */
    public const string HOOK = 'orillacart_network_upgrade';
    public const int MAX_ATTEMPTS = 5;
    /** The sites read from the network's table at once. */
    private const int PAGE = 100;

    public function __construct(
        private SiteUpgrades $upgrades,
        private PluginContext $context,
    ) {}

    /**
     * Begins the walk for the files' build when it has not, and makes sure a walk under way has a batch scheduled.
     */
    public function start(): void
    {
        if (!is_multisite()) {
            return;
        }
        $walk = $this->walk();
        if ($walk === null) {
            $walk = new NetworkWalk($this->context->build, $this->countSites(), at: time());
            $this->save($walk);
        }
        if (!$walk->finished()) {
            $this->schedule($this->nextAt($walk));
        }
    }

    /** The walk for the files' build, if one began. */
    public function walk(): ?NetworkWalk
    {
        $walk = NetworkWalk::fromOption(get_site_option(self::OPTION));

        return $walk !== null && $walk->build === $this->context->build ? $walk : null;
    }

    /**
     * Goes on with the walk for about $seconds: the sites not gone through yet, then the failed ones that are due.
     * Another batch already running makes it return at once.
     */
    public function step(int $seconds): void
    {
        if (!is_multisite()) {
            return;
        }
        $wpdb = $this->wpdb();
        if ($wpdb->get_var((string) $wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::OPTION)) !== '1') {
            return;
        }
        try {
            // The batch that held the lock may have just saved: read the option, not the cache.
            wp_cache_delete(get_current_network_id() . ':' . self::OPTION, 'site-options');
            $walk = $this->walk();
            if ($walk === null || $walk->finished()) {
                return;
            }
            $until = microtime(true) + $seconds;
            while (!$walk->walked && microtime(true) < $until) {
                $sites = $this->sitesAfter($walk->cursor);
                if ($sites === []) {
                    $walk->walked = true;
                    break;
                }
                foreach ($sites as $siteId) {
                    if (microtime(true) >= $until) {
                        break;
                    }
                    $this->upgrade($walk, $siteId);
                    $walk->cursor = $siteId;
                    ++$walk->checked;
                }
                // How far it got shows while it goes on.
                $this->save($walk);
            }
            if ($walk->walked) {
                foreach ($walk->failed as $siteId => $failure) {
                    if (microtime(true) >= $until) {
                        break;
                    }
                    if ($failure['attempts'] < self::MAX_ATTEMPTS && $failure['retry_at'] <= time()) {
                        $this->upgrade($walk, $siteId);
                    }
                }
            }
            $this->save($walk);
            if (!$walk->finished()) {
                $this->schedule($this->nextAt($walk));
            }
        } finally {
            $wpdb->query((string) $wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::OPTION));
        }
    }

    /** The network admin asks for the failed sites again: they are tried in the next batch. */
    public function retry(): void
    {
        $walk = $this->walk();
        if ($walk === null) {
            return;
        }
        foreach ($walk->failed as $siteId => $failure) {
            $walk->failed[$siteId] = ['error' => $failure['error'], 'attempts' => 0, 'retry_at' => 0];
        }
        $this->save($walk);
        $this->schedule(time());
    }

    /**
     * WP-CLI went through every shop itself: what it could not upgrade waits for the network admin.
     *
     * @param array<int, string> $failed the error by site id
     */
    public function ranEverySite(int $upgraded, array $failed): void
    {
        $walk = new NetworkWalk($this->context->build, $this->countSites(), PHP_INT_MAX, $this->countSites(), $upgraded, walked: true, at: time());
        foreach ($failed as $siteId => $error) {
            $walk->failed[$siteId] = ['error' => $error, 'attempts' => self::MAX_ATTEMPTS, 'retry_at' => 0];
        }
        $this->save($walk);
    }

    public function save(NetworkWalk $walk): void
    {
        update_site_option(self::OPTION, $walk->toOption());
    }

    public function forget(): void
    {
        delete_site_option(self::OPTION);
        $this->onMainSite(static fn() => wp_clear_scheduled_hook(self::HOOK));
    }

    /** One site: skipped when it has no shop or is up to date already, as most are after their own visitors. */
    private function upgrade(NetworkWalk $walk, int $siteId): void
    {
        if (!$this->upgrades->runsShop($siteId) || get_blog_option($siteId, PluginLifecycle::VERSION_OPTION) === $this->context->build) {
            unset($walk->failed[$siteId]);

            return;
        }
        $outcome = $this->upgrades->upgradeSite($siteId);
        if ($outcome->isUpToDate() || $outcome->isNoShop()) {
            if ($outcome->isUpToDate()) {
                ++$walk->upgraded;
            }
            unset($walk->failed[$siteId]);

            return;
        }
        $attempts = ($walk->failed[$siteId]['attempts'] ?? 0) + 1;
        $walk->failed[$siteId] = [
            'error' => $outcome->isBusy() ? 'Another request was upgrading the site\'s database.' : $outcome->error,
            'attempts' => $attempts,
            'retry_at' => time() + 60 * 2 ** ($attempts - 1),
        ];
    }

    /** When the next batch is due: now while sites are left, else when the first failure may be tried again. */
    private function nextAt(NetworkWalk $walk): ?int
    {
        if (!$walk->walked) {
            return time();
        }
        $due = array_column(array_filter($walk->failed, static fn(array $failure): bool => $failure['attempts'] < self::MAX_ATTEMPTS), 'retry_at');

        return $due === [] ? null : max(time(), min($due));
    }

    private function schedule(?int $at): void
    {
        if ($at === null) {
            return;
        }
        $this->onMainSite(static function () use ($at): void {
            $next = wp_next_scheduled(self::HOOK);
            if ($next !== false && $next <= $at) {
                return;
            }
            if ($next !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            wp_schedule_single_event($at, self::HOOK);
        });
    }

    /**
     * @param \Closure(): mixed $step
     */
    private function onMainSite(\Closure $step): void
    {
        $switch = get_current_blog_id() !== get_main_site_id();
        if ($switch) {
            switch_to_blog(get_main_site_id());
        }
        try {
            $step();
        } finally {
            if ($switch) {
                restore_current_blog();
            }
        }
    }

    /**
     * @return list<int>
     */
    private function sitesAfter(int $cursor): array
    {
        $wpdb = $this->wpdb();

        return array_map(intval(...), $wpdb->get_col((string) $wpdb->prepare(
            'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d AND deleted = 0 ORDER BY blog_id LIMIT %d',
            $wpdb->blogs,
            get_current_network_id(),
            $cursor,
            self::PAGE,
        )));
    }

    private function countSites(): int
    {
        $wpdb = $this->wpdb();

        return (int) $wpdb->get_var((string) $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE site_id = %d AND deleted = 0', $wpdb->blogs, get_current_network_id()));
    }

    private function wpdb(): \wpdb
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        \assert($wpdb instanceof \wpdb);

        return $wpdb;
    }
}
