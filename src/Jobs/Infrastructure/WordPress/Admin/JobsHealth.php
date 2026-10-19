<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress\Admin;

use OrillaCart\Jobs\Application\Dto\LaneView;
use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether anything runs the shop's background jobs (its emails, its search index, its reports), and whether
 * some failed.
 */
final readonly class JobsHealth implements HealthCheck
{
    public function __construct(private JobOverview $jobs) {}

    public static function id(): string
    {
        return 'jobs';
    }

    public static function label(): string
    {
        return __('The shop’s background jobs', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $overview = $this->jobs->overview();
        $screen = admin_url('admin.php?page=orillacart-jobs');
        $open = __('Open Background jobs', 'orillacart');
        if ($overview->runner === 'none') {
            return HealthStatus::critical(
                __('Nothing runs the shop’s background jobs', 'orillacart'),
                $overview->cronDisabled
                    ? __('WP-Cron is turned off (DISABLE_WP_CRON) and no worker is running, so order emails, the search index and the reports wait. Have the server run "wp cron event run --due-now" every minute, or run workers: wp orillacart jobs work --loop.', 'orillacart')
                    : __('No worker is running and WP-Cron has not run lately, so order emails, the search index and the reports wait. Make sure WP-Cron runs every minute, or run workers: wp orillacart jobs work --loop.', 'orillacart'),
                $screen,
                $open,
            );
        }
        if ($overview->failed > 0) {
            return HealthStatus::recommended(
                \sprintf(
                    /* translators: %d: failed jobs */
                    _n('%d background job of the shop failed', '%d background jobs of the shop failed', $overview->failed, 'orillacart'),
                    $overview->failed,
                ),
                __('They are kept with their errors: retry them once the cause is fixed, or delete them.', 'orillacart'),
                $screen,
                $open,
            );
        }

        return HealthStatus::good(
            __('The shop’s background jobs run', 'orillacart'),
            $overview->runner === 'workers' ? __('Workers run them.', 'orillacart') : __('WP-Cron runs them.', 'orillacart'),
        );
    }

    public function info(): array
    {
        $overview = $this->jobs->overview();

        return [
            'runner' => ['label' => __('Jobs run by', 'orillacart'), 'value' => match ($overview->runner) {
                'workers' => __('workers', 'orillacart'),
                'cron' => 'WP-Cron',
                default => __('nothing', 'orillacart'),
            }],
            'workers' => ['label' => __('Workers', 'orillacart'), 'value' => (string) \count($overview->workers)],
            'cron' => ['label' => __('WP-Cron last ran the jobs', 'orillacart'), 'value' => $overview->cronRanAt ?? __('never', 'orillacart')],
            'cron_disabled' => ['label' => 'DISABLE_WP_CRON', 'value' => $overview->cronDisabled ? 'true' : 'false'],
            'due' => ['label' => __('Jobs due', 'orillacart'), 'value' => (string) array_sum(array_map(static fn(LaneView $lane): int => $lane->due, $overview->lanes))],
            'failed' => ['label' => __('Failed jobs', 'orillacart'), 'value' => (string) $overview->failed],
        ];
    }
}
