<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Platform\WordPress\SiteKernels;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Tells the network's super admins how the upgrade of the network's shops goes after an update: how many sites
 * are left, which could not be upgraded with a way to try them again, and that it is done.
 */
#[AsHook('network_admin_notices', method: 'notice')]
#[AsHook('admin_post_' . self::RETRY, method: 'retry')]
final readonly class NetworkUpgradeNotice
{
    private const string RETRY = 'orillacart_retry_network_upgrade';

    public function __construct(
        private SiteUpgrades $upgrades,
        private PluginContext $context,
    ) {}

    public function notice(): void
    {
        $walk = $this->upgrades->walking();
        if ($walk === null || !current_user_can('manage_network_plugins')) {
            return;
        }
        $this->upgrades->forgetSettledFailures($walk['coordinator']);
        [$left, $failed, $error] = $this->onCoordinator($walk['coordinator'], static function (Jobs $jobs): array {
            $failed = $jobs->failed(UpgradeSites::TYPE, 1);

            return [$jobs->pending(UpgradeSites::TYPE), $jobs->failedCount(UpgradeSites::TYPE), isset($failed[0]) ? $failed[0]->about . ': ' . $failed[0]->error : ''];
        });
        $site = get_home_url($walk['coordinator']);
        if ($left === 0 && $failed === 0) {
            $this->upgrades->walked();
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(\sprintf(
                    /* translators: %d: how many sites */
                    _n('OrillaCart upgraded the database of the network\'s %d other shop.', 'OrillaCart upgraded the databases of the network\'s %d other shops.', $walk['sites'], 'orillacart'),
                    $walk['sites'],
                )),
            );

            return;
        }
        if ($left > 0) {
            printf(
                '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p>%3$s <code>%4$s</code></p></div>',
                esc_html(\sprintf(
                    /* translators: 1: sites left, 2: sites in all */
                    __('OrillaCart is upgrading the databases of the network\'s shops: %1$d of %2$d sites left.', 'orillacart'),
                    $left,
                    $walk['sites'],
                )),
                esc_html(\sprintf(
                    /* translators: %s: the site's address */
                    __('They run in the background on %s, when that site has visitors or a worker serves the network (wp orillacart jobs work --network); each shop also upgrades itself on its first visit.', 'orillacart'),
                    $site,
                )),
                esc_html__('To upgrade them all now:', 'orillacart'),
                esc_html('wp orillacart migrate --network'),
            );
        }
        if ($failed > 0) {
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong></p><p><code>%2$s</code></p><p><a class="button" href="%3$s">%4$s</a> %5$s <code>%6$s</code></p></div>',
                esc_html(\sprintf(
                    /* translators: %d: how many sites */
                    _n('OrillaCart could not upgrade the database of %d shop of the network.', 'OrillaCart could not upgrade the databases of %d shops of the network.', $failed, 'orillacart'),
                    $failed,
                )),
                esc_html($error),
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::RETRY), self::RETRY)),
                esc_html__('Try them again', 'orillacart'),
                esc_html__('or run', 'orillacart'),
                esc_html('wp orillacart migrate --network'),
            );
        }
    }

    public function retry(): void
    {
        if (!current_user_can('manage_network_plugins') || check_admin_referer(self::RETRY) === false) {
            wp_die(esc_html__('You may not do this.', 'orillacart'), '', ['response' => 403]);
        }
        $walk = $this->upgrades->walking();
        if ($walk !== null) {
            $this->onCoordinator($walk['coordinator'], static fn(Jobs $jobs): int => $jobs->retryFailed(array_map(static fn($job): string => $job->id, $jobs->failed(UpgradeSites::TYPE, 1000))));
        }
        wp_safe_redirect(network_admin_url());
        exit;
    }

    /**
     * @template T
     *
     * @param \Closure(Jobs): T $read
     *
     * @return T
     */
    private function onCoordinator(int $siteId, \Closure $read): mixed
    {
        return SiteKernels::run($siteId, $this->context, static function (Kernel $kernel) use ($read): mixed {
            $jobs = $kernel->container()->get(Jobs::class);
            \assert($jobs instanceof Jobs);

            return $read($jobs);
        });
    }
}
