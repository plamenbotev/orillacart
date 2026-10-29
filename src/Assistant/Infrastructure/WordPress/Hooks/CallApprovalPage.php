<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress\Hooks;

use OrillaCart\Assistant\Application\CallApprovals;
use OrillaCart\Assistant\Application\Dto\CallApproval;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The owner's yes for an assistant whose app cannot ask it: a screen in wp-admin, reached by the link the
 * assistant was given, that shows what the call would do. Only the manager the call is made as answers it; the
 * answer is a form with a nonce, sent to admin-post.php, and the same call made again then runs once.
 */
#[AsHook('admin_menu', method: 'register')]
#[AsHook('admin_post_' . self::ACTION, method: 'answer')]
#[AsHook('admin_title', method: 'title', filter: true)]
final readonly class CallApprovalPage
{
    public const string PAGE = 'orillacart-approve';
    public const string ACTION = 'orillacart_approve';

    public function __construct(private CallApprovals $approvals) {}

    public function register(): void
    {
        add_submenu_page('', __('The assistant asks', 'orillacart'), '', Capability::MANAGE_SHOP, self::PAGE, $this->render(...));
    }

    /** A screen outside the menu has no title of its own. */
    public function title(mixed $title): mixed
    {
        return ($_GET['page'] ?? null) === self::PAGE && \is_string($title) ? __('The assistant asks', 'orillacart') . $title : $title;
    }

    public function render(): void
    {
        $approval = $this->approvals->byToken(self::token($_GET));
        echo '<div class="wrap orillacart-approve">';
        if ($approval === null) {
            printf('<h1>%s</h1><p>%s</p></div>', esc_html__('Nothing to answer', 'orillacart'), esc_html__('This request ran out, or ran already. Ask the assistant to try again if it still should.', 'orillacart'));

            return;
        }
        if ($approval->userId !== get_current_user_id()) {
            $owner = get_userdata($approval->userId);
            printf(
                '<h1>%s</h1><p>%s</p></div>',
                esc_html__('Not yours to answer', 'orillacart'),
                esc_html(\sprintf(/* translators: %s: a user's name */ __('The assistant asks %s, whose key or login it works with. They answer it here, logged in as themselves.', 'orillacart'), $owner instanceof \WP_User ? $owner->display_name : '#' . $approval->userId)),
            );

            return;
        }
        printf('<h1>%s</h1>', esc_html(\sprintf(/* translators: %s: what a tool does, such as "Refund order" */ __('The assistant wants to: %s', 'orillacart'), $approval->title)));
        printf('<p><strong>%s</strong></p>', esc_html($approval->risk));
        printf('<div class="card" style="max-width:60em"><p>%s</p></div>', nl2br(esc_html($approval->what)));
        if ($approval->state !== CallApproval::WAITING) {
            printf(
                '<div class="notice notice-%s inline"><p>%s</p></div></div>',
                $approval->state === CallApproval::APPROVED ? 'success' : 'warning',
                esc_html($approval->state === CallApproval::APPROVED
                    ? __('You said yes. Tell the assistant to go ahead: it makes the same call again, and it runs once.', 'orillacart')
                    : __('You said no: nothing is done. The assistant is told so when it tries again.', 'orillacart')),
            );

            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="request" value="%s">', esc_attr(self::ACTION), esc_attr($approval->token));
        wp_nonce_field(self::ACTION);
        printf(
            '<p>%s</p><p><button type="submit" name="decision" value="approve" class="button button-primary">%s</button> <button type="submit" name="decision" value="refuse" class="button">%s</button></p>',
            esc_html(\sprintf(/* translators: %s: a time of day */ __('You can answer until %s.', 'orillacart'), wp_date(\is_string($format = get_option('time_format')) ? $format : 'H:i', $approval->expiresAt))),
            esc_html__('Yes, do it', 'orillacart'),
            esc_html__('No', 'orillacart'),
        );
        echo '</form></div>';
    }

    public function answer(): void
    {
        check_admin_referer(self::ACTION);
        $approval = $this->approvals->byToken(self::token(wp_unslash($_POST)));
        if ($approval === null || !current_user_can(Capability::MANAGE_SHOP) || $approval->userId !== get_current_user_id()) {
            wp_die(esc_html__('This request ran out, or is not yours to answer.', 'orillacart'), '', ['response' => 403]);
        }
        if ($approval->state === CallApproval::WAITING) {
            $this->approvals->answer($approval, ($_POST['decision'] ?? null) === 'approve');
        }
        wp_safe_redirect($this->approvals->url($approval));
        exit;
    }

    /**
     * @param array<mixed> $input
     */
    private static function token(array $input): string
    {
        $token = $input['request'] ?? '';

        return \is_string($token) ? sanitize_key($token) : '';
    }
}
