<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Infrastructure\WordPress\Hooks;

use OrillaCart\Settings\Application\SetupState;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Until the shop manager went through the setup, the admin offers it at the top of each screen; "Not now"
 * stops it, as finishing does. Shop → Setup stays in the menu either way.
 */
#[AsHook('admin_notices', method: 'notice')]
#[AsHook('admin_post_orillacart_skip_setup', method: 'skip')]
final readonly class SetupNotice
{
    public function __construct(private SetupState $setup) {}

    public function notice(): void
    {
        $screen = get_current_screen();
        if ($this->setup->finished() || !current_user_can(Capability::MANAGE_SHOP) || ($screen !== null && str_ends_with($screen->id, 'orillacart-setup'))) {
            return;
        }
        printf(
            '<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
            esc_html__('Your shop is ready to set up.', 'orillacart'),
            esc_html__('Say where it sells from, how customers pay and how it ships, and add your first products, in a few steps.', 'orillacart'),
            esc_url(admin_url('admin.php?page=orillacart-setup')),
            esc_html__('Set up the shop', 'orillacart'),
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=orillacart_skip_setup'), 'orillacart_skip_setup')),
            esc_html__('Not now', 'orillacart'),
        );
    }

    public function skip(): void
    {
        if (!current_user_can(Capability::MANAGE_SHOP) || check_admin_referer('orillacart_skip_setup') === false) {
            wp_die(esc_html__('You may not do this.', 'orillacart'), '', ['response' => 403]);
        }
        $this->setup->finish();
        $back = wp_get_referer();
        wp_safe_redirect(\is_string($back) ? $back : admin_url());
        exit;
    }
}
