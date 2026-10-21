<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Ordering\Infrastructure\WordPress\OrdersToDo;
use OrillaCart\Reports\Application\Dto\ReportQuery;
use OrillaCart\Reports\Application\Reports;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The dashboard's "Shop" box for shop managers: today's and the last seven days' sales as the Reports screen
 * counts them, the orders that wait, and the items low in stock, each a link to where to act.
 */
#[AsHook('wp_dashboard_setup')]
final readonly class ShopDashboardWidget
{
    public function __construct(
        private Reports $reports,
        private OrdersToDo $toDo,
        private LowStock $lowStock,
        private TemplateRenderer $templates,
    ) {}

    public function __invoke(): void
    {
        if (current_user_can(Capability::MANAGE_SHOP)) {
            wp_add_dashboard_widget('orillacart_shop', __('Shop', 'orillacart'), $this->render(...));
        }
    }

    public function render(): void
    {
        $today = (string) wp_date('Y-m-d');
        $weekAgo = (string) wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS);
        $counts = $this->toDo->counts();
        // The template escapes what it prints.
        echo $this->templates->render('admin/dashboard.html.twig', [
            'today' => $this->reports->sales(new ReportQuery($today, $today, compare: ReportQuery::NONE))->totals,
            'week' => $this->reports->sales(new ReportQuery($weekAgo, $today, compare: ReportQuery::NONE))->totals,
            'ship' => $counts['ship'],
            'attention' => $counts['attention'],
            'low_stock' => \count($this->lowStock->items()),
            'urls' => [
                'ship' => OrdersToDo::url('status=processing'),
                'attention' => OrdersToDo::url('attention=yes'),
                'stock' => admin_url('admin.php?page=orillacart-stock'),
                'reports' => admin_url('admin.php?page=orillacart-reports'),
            ],
        ]);
    }
}
