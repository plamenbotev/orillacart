<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress;

use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;

/**
 * What waits for the shop: orders to ship (processing) and orders that need attention, for the admin bar and
 * the dashboard. Counted once a minute at most: every admin page shows them.
 */
final readonly class OrdersToDo
{
    private const string CACHE = 'orillacart_orders_to_do';

    public function __construct(private OrderService $orders) {}

    /**
     * @return array{ship: int, attention: int}
     */
    public function counts(): array
    {
        $cached = get_transient(self::CACHE);
        if (\is_array($cached) && \is_int($cached['ship'] ?? null) && \is_int($cached['attention'] ?? null)) {
            return ['ship' => $cached['ship'], 'attention' => $cached['attention']];
        }
        $counts = [
            'ship' => $this->orders->orders(new OrderQuery(status: 'processing', perPage: 1), true)->total,
            'attention' => $this->orders->orders(new OrderQuery(perPage: 1, attention: true), true)->total,
        ];
        set_transient(self::CACHE, $counts, MINUTE_IN_SECONDS);

        return $counts;
    }

    /** The Orders screen with a filter: status=processing, or attention=yes. */
    public static function url(string $filter = ''): string
    {
        return admin_url('admin.php?page=orillacart-orders' . ($filter === '' ? '' : '&' . $filter));
    }
}
