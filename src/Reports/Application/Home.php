<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Extension\Home\HomeContributor;
use OrillaCart\Extension\Home\HomeLine;
use OrillaCart\Extension\Home\HomeTile;
use OrillaCart\Jobs\Application\JobOverview;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Reports\Application\Dto\ReportQuery;
use OrillaCart\Settings\Application\SetupState;
use OrillaCart\Settings\Application\StatusReport;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The Home screen (design 52): what the shop did today and this week, what needs the shop manager now, and the
 * latest orders. One request, so the screen opens at once; the figures are the Reports screen's, by the shop's
 * calendar days.
 */
final readonly class Home
{
    private const int RECENT = 5;
    /** How long an overview is shown again as it was: the to-do counts in the admin bar are kept as long. */
    private const int KEPT_FOR = 60;

    public function __construct(
        private Reports $reports,
        private ShopCalendar $calendar,
        private OrderService $orders,
        private LowStock $lowStock,
        private JobOverview $jobs,
        private StatusReport $status,
        private SetupState $setup,
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ?HomeCache $cache = null,
    ) {}

    /**
     * @return array{
     *     today: array{orders: int, net: string, average_order: string, items: int},
     *     week: array{orders: int, net: string, average_order: string, items: int, series: list<array{date: string, net: string, orders: int}>},
     *     currency: string,
     *     needs_you: list<array{kind: string, text: string, count: int, url: string, tone: string}>,
     *     setup_finished: bool,
     *     recent: list<object>,
     *     tiles: list<array{label: string, value: string, detail: string, url: string}>
     * }
     */
    public function overview(): array
    {
        // Kept a minute, by the day it is for: the figures do not step back over midnight. The cache keeps each
        // user's own, as add-ons' lines may say what one user may see.
        $day = new \DateTimeImmutable('today', $this->calendar->timezone());
        $key = $day->format('Y-m-d');
        $kept = $this->cache?->get($key);
        if ($kept !== null) {
            /** @var array{today: array{orders: int, net: string, average_order: string, items: int}, week: array{orders: int, net: string, average_order: string, items: int, series: list<array{date: string, net: string, orders: int}>}, currency: string, needs_you: list<array{kind: string, text: string, count: int, url: string, tone: string}>, setup_finished: bool, recent: list<object>, tiles: list<array{label: string, value: string, detail: string, url: string}>} $kept */
            return $kept;
        }
        $overview = $this->build($day);
        $this->cache?->put($key, $overview, self::KEPT_FOR);

        return $overview;
    }

    /**
     * @return array{
     *     today: array{orders: int, net: string, average_order: string, items: int},
     *     week: array{orders: int, net: string, average_order: string, items: int, series: list<array{date: string, net: string, orders: int}>},
     *     currency: string,
     *     needs_you: list<array{kind: string, text: string, count: int, url: string, tone: string}>,
     *     setup_finished: bool,
     *     recent: list<object>,
     *     tiles: list<array{label: string, value: string, detail: string, url: string}>
     * }
     */
    private function build(\DateTimeImmutable $day): array
    {
        $today = $day->format('Y-m-d');
        $weekAgo = $day->modify('-6 days')->format('Y-m-d');
        $todaySales = $this->reports->sales(new ReportQuery($today, $today, interval: 'day', compare: ReportQuery::NONE));
        $weekSales = $this->reports->sales(new ReportQuery($weekAgo, $today, interval: 'day', compare: ReportQuery::NONE));

        return [
            'today' => $this->figures($todaySales->totals->orders, $todaySales->totals->net, $todaySales->totals->averageOrder, $todaySales->totals->items),
            'week' => $this->figures($weekSales->totals->orders, $weekSales->totals->net, $weekSales->totals->averageOrder, $weekSales->totals->items) + [
                'series' => array_map(static fn(array $point): array => ['date' => $point['date'], 'net' => $point['net'], 'orders' => $point['orders']], $weekSales->series),
            ],
            'currency' => $weekSales->frame->currency,
            'needs_you' => $this->needsYou($day),
            'setup_finished' => $this->setup->finished(),
            'recent' => $this->orders->orders(new OrderQuery(perPage: self::RECENT), true)->orders,
            'tiles' => $this->tiles(),
        ];
    }

    /** @return array{orders: int, net: string, average_order: string, items: int} */
    private function figures(int $orders, string $net, string $averageOrder, int $items): array
    {
        return ['orders' => $orders, 'net' => $net, 'average_order' => $averageOrder, 'items' => $items];
    }

    /**
     * What waits, most pressing first: each line says how many and where to act; nothing when nothing waits.
     *
     * @return list<array{kind: string, text: string, count: int, url: string, tone: string}>
     */
    private function needsYou(\DateTimeImmutable $day): array
    {
        $lines = [];
        $line = static function (string $kind, int $count, string $text, string $url, string $tone = HomeLine::WARNING) use (&$lines): void {
            if ($count > 0) {
                $lines[] = ['kind' => $kind, 'text' => $text, 'count' => $count, 'url' => $url, 'tone' => $tone];
            }
        };

        $attention = $this->orders->orders(new OrderQuery(perPage: 1, attention: true), true)->total;
        $line('attention', $attention, 'orders need attention', 'orders?attention=yes', HomeLine::ATTENTION);
        $yesterday = $day->modify('-1 day')->format(\DATE_ATOM);
        $held = $this->orders->orders(new OrderQuery(status: 'on_hold', perPage: 1, placedBefore: $yesterday), true)->total;
        $line('on_hold', $held, 'orders on hold since before yesterday', 'orders?status=on_hold');
        $ship = $this->orders->orders(new OrderQuery(status: 'processing', perPage: 1), true)->total;
        $line('ship', $ship, 'orders to ship', 'orders?status=processing', HomeLine::INFO);
        $line('low_stock', \count($this->lowStock->items()), 'items low in stock', 'stock');
        $line('failed_jobs', $this->jobs->overview()->failed, 'background jobs failed', 'jobs');
        $checks = array_filter($this->status->report()['checks'], static fn(array $check): bool => $check['status'] !== 'good');
        $critical = array_filter($checks, static fn(array $check): bool => $check['status'] === 'critical');
        $line('status', \count($checks), 'status checks worth a look', 'status', $critical === [] ? HomeLine::WARNING : HomeLine::ATTENTION);

        foreach ($this->extensions->homeContributors() as $contributor) {
            foreach ($this->guarded($contributor, $contributor->needsYou(...)) as $extra) {
                if ($extra instanceof HomeLine) {
                    $lines[] = ['kind' => $contributor->id(), 'text' => $extra->text, 'count' => 0, 'url' => $extra->url, 'tone' => $extra->tone];
                }
            }
        }

        return $lines;
    }

    /** @return list<array{label: string, value: string, detail: string, url: string}> */
    private function tiles(): array
    {
        $tiles = [];
        foreach ($this->extensions->homeContributors() as $contributor) {
            foreach ($this->guarded($contributor, $contributor->tiles(...)) as $tile) {
                if ($tile instanceof HomeTile) {
                    $tiles[] = ['label' => $tile->label, 'value' => $tile->value, 'detail' => $tile->detail, 'url' => $tile->url];
                }
            }
        }

        return $tiles;
    }

    /**
     * An add-on that throws loses its place on the screen, not the screen.
     *
     * @param callable(): list<object> $ask
     *
     * @return list<object>
     */
    private function guarded(HomeContributor $contributor, callable $ask): array
    {
        try {
            return $ask();
        } catch (\Throwable $failure) {
            $this->logger->error('The add-on {id} failed on the Home screen: {message}', ['id' => $contributor->id(), 'message' => $failure->getMessage(), 'exception' => $failure]);

            return [];
        }
    }
}
