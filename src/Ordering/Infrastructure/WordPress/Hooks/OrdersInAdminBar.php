<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress\Hooks;

use OrillaCart\Ordering\Infrastructure\WordPress\OrdersToDo;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The admin bar tells shop managers, on every page, how many orders wait to ship and how many need attention,
 * each a link to them on the Orders screen.
 */
#[AsHook('admin_bar_menu', priority: 80)]
final readonly class OrdersInAdminBar
{
    public function __construct(private OrdersToDo $toDo) {}

    public function __invoke(\WP_Admin_Bar $bar): void
    {
        if (!current_user_can(Capability::MANAGE_SHOP)) {
            return;
        }
        $counts = $this->toDo->counts();
        $waiting = $counts['ship'] + $counts['attention'];
        $bar->add_node([
            'id' => 'orillacart-orders',
            'title' => '<span class="ab-icon dashicons dashicons-cart" aria-hidden="true"></span><span class="ab-label">'
                . esc_html($waiting > 0 ? (string) $waiting : __('Orders', 'orillacart')) . '</span>'
                . ($waiting > 0 ? '<span class="screen-reader-text"> ' . esc_html__('orders wait for you', 'orillacart') . '</span>' : ''),
            'href' => OrdersToDo::url(),
            'meta' => ['title' => __('Orders', 'orillacart')],
        ]);
        /* translators: %d: how many orders */
        $bar->add_node(['parent' => 'orillacart-orders', 'id' => 'orillacart-orders-ship', 'title' => esc_html(\sprintf(_n('%d to ship', '%d to ship', $counts['ship'], 'orillacart'), $counts['ship'])), 'href' => OrdersToDo::url('status=processing')]);
        /* translators: %d: how many orders */
        $bar->add_node(['parent' => 'orillacart-orders', 'id' => 'orillacart-orders-attention', 'title' => esc_html(\sprintf(_n('%d needs attention', '%d need attention', $counts['attention'], 'orillacart'), $counts['attention'])), 'href' => OrdersToDo::url('attention=yes')]);
    }
}
