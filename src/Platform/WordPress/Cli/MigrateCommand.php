<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Platform\WordPress\Upgrades\SiteUpgrades;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Brings the database up to the files' version now, on this site or on every shop of the network.
 */
final readonly class MigrateCommand implements CliCommand
{
    /** How long it waits for a request that is upgrading the same site. */
    private const int WAIT_SECONDS = 60;

    public function __construct(private SiteUpgrades $upgrades) {}

    public static function name(): string
    {
        return 'orillacart migrate';
    }

    /**
     * Runs the changes to the tables that have not run, and the plugin's upgrade steps. Ends with an error when a
     * site could not be upgraded, so a deploy script can stop on it.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Every site of the network that runs the shop, one after the other, instead of the site --url names.
     *
     * ## EXAMPLES
     *
     *     wp orillacart migrate
     *     wp orillacart migrate --network
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        if (!isset($options['network'])) {
            $this->finish($this->upgrade(get_current_blog_id(), false) ? 0 : 1, 1);

            return;
        }
        if (!is_multisite()) {
            \WP_CLI::error('This site is not a network: run it without --network.');
        }
        $sites = array_keys($this->upgrades->shops());
        $failed = 0;
        foreach ($sites as $siteId) {
            if (!$this->upgrade($siteId, true)) {
                ++$failed;
            }
        }
        // What the walk queued and could not do is done now.
        $coordinator = $this->upgrades->walking()['coordinator'] ?? $this->upgrades->coordinator();
        if ($coordinator !== null) {
            $this->upgrades->forgetSettledFailures($coordinator);
        }
        if ($failed === 0) {
            $this->upgrades->walked();
        }
        $this->finish($failed, \count($sites));
    }

    private function upgrade(int $siteId, bool $named): bool
    {
        // Going through the network itself, it queues no walk.
        $outcome = $this->upgrades->upgradeSite($siteId, force: true, wait: self::WAIT_SECONDS, walk: !$named);
        $site = $named ? \sprintf('Site #%d (%s): ', $siteId, get_home_url($siteId)) : '';
        if ($outcome->isUpgraded()) {
            \WP_CLI::log($site . 'migrated ' . ($outcome->migrations === [] ? 'nothing, ran the upgrade steps' : implode(', ', $outcome->migrations)) . '.');
        } elseif ($outcome->isCurrent()) {
            \WP_CLI::log($site . 'the database is up to date.');
        } elseif ($outcome->isNoShop()) {
            \WP_CLI::log($site . 'does not run the shop.');
        } elseif ($outcome->isBusy()) {
            \WP_CLI::warning($site . \sprintf('another request was still upgrading it after %d seconds.', self::WAIT_SECONDS));

            return false;
        } else {
            \WP_CLI::warning($site . 'failed: ' . $outcome->error);

            return false;
        }

        return true;
    }

    private function finish(int $failed, int $sites): void
    {
        if ($failed > 0) {
            \WP_CLI::error($sites === 1 ? 'The database could not be upgraded.' : \sprintf('%d of %d sites could not be upgraded.', $failed, $sites));
        }
        \WP_CLI::success($sites === 1 ? 'Done.' : \sprintf('%d sites are up to date.', $sites));
    }
}
