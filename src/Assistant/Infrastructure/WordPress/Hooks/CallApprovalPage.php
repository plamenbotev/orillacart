<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress\Hooks;

use OrillaCart\Assistant\Application\AssistantKeys;
use OrillaCart\Assistant\Application\CallApprovals;
use OrillaCart\Assistant\Application\Dto\CallApproval;
use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The owner's yes for an assistant whose app cannot ask it: a screen in wp-admin, reached by the link the
 * assistant was given, that shows what the call would do. Only the manager the call is made as answers it; the
 * answer is a form with a nonce, sent to admin-post.php, and the same call made again then runs once. For the
 * risks whose calls are much alike (AssistantChoices::ALLOWABLE), the owner may also let the key run the tool
 * unasked for a while, as long as the key's choices say; that is in the change log, and the key's Assistant
 * settings list it to take back.
 */
#[AsHook('admin_menu', method: 'register')]
#[AsHook('admin_post_' . self::ACTION, method: 'answer')]
#[AsHook('admin_title', method: 'title', filter: true)]
final readonly class CallApprovalPage
{
    public const string PAGE = 'orillacart-approve';
    public const string ACTION = 'orillacart_approve';

    /** The decision that says yes and lets the key run the tool unasked for a while. */
    private const string ALLOW = 'approve_for_a_while';

    public function __construct(private CallApprovals $approvals, private AssistantKeys $keys, private ChangeLog $log) {}

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
                esc_html(match (true) {
                    $approval->state !== CallApproval::APPROVED => __('You said no: nothing is done. The assistant is told so when it tries again.', 'orillacart'),
                    $approval->allowedUntil !== null => \sprintf(
                        /* translators: 1: what a tool does, such as "Save settings", 2: a time of day */
                        __('You said yes. Tell the assistant to go ahead: it makes the same call again, and until %2$s it may "%1$s" without asking. Take that back in the key\'s Assistant settings.', 'orillacart'),
                        $approval->title,
                        self::time($approval->allowedUntil),
                    ),
                    default => __('You said yes. Tell the assistant to go ahead: it makes the same call again, and it runs once.', 'orillacart'),
                }),
            );

            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="request" value="%s">', esc_attr(self::ACTION), esc_attr($approval->token));
        wp_nonce_field(self::ACTION);
        $allow = '';
        if ($approval->mayAllow()) {
            $allow = \sprintf(
                ' <button type="submit" name="decision" value="%s" class="button">%s</button>',
                esc_attr(self::ALLOW),
                esc_html(\sprintf(
                    /* translators: 1: how long, such as "1 hour" */
                    __('Yes, and don\'t ask again for this tool for %1$s', 'orillacart'),
                    human_time_diff(0, $approval->allowFor * MINUTE_IN_SECONDS),
                )),
            );
        }
        printf(
            '<p>%s</p><p><button type="submit" name="decision" value="approve" class="button button-primary">%s</button>%s <button type="submit" name="decision" value="refuse" class="button">%s</button></p>',
            esc_html(\sprintf(/* translators: %s: a time of day */ __('You can answer until %s.', 'orillacart'), self::time($approval->expiresAt))),
            esc_html__('Yes, do it', 'orillacart'),
            $allow,
            esc_html__('No', 'orillacart'),
        );
        if ($approval->mayAllow()) {
            printf(
                '<p class="description">%s</p>',
                esc_html(\sprintf(
                    /* translators: %s: what a tool does, such as "Save settings" */
                    __('Not asking again lets the assistant "%s" with any arguments, with this key only, until the time runs out; whoever holds the key could then use it unasked too. You can take it back in the key\'s Assistant settings.', 'orillacart'),
                    $approval->title,
                )),
            );
        }
        echo '</form></div>';
    }

    public function answer(): void
    {
        check_admin_referer(self::ACTION);
        $approval = $this->approvals->byToken(self::token(wp_unslash($_POST)));
        if ($approval === null || !current_user_can(Capability::MANAGE_SHOP) || $approval->userId !== get_current_user_id()) {
            wp_die(esc_html__('This request ran out, or is not yours to answer.', 'orillacart'), '', ['response' => 403]);
        }
        $decision = $_POST['decision'] ?? null;
        if ($approval->state === CallApproval::WAITING) {
            $until = null;
            if ($decision === self::ALLOW && $approval->mayAllow() && $approval->keyId !== null) {
                $until = time() + $approval->allowFor * MINUTE_IN_SECONDS;
                try {
                    $this->keys->allow($approval->keyId, $approval->userId, $approval->tool, $until);
                    $this->log->record($approval->userId, 'POST', '/assistant/allowances', '/assistant/allowances/' . $approval->tool, ['key' => $approval->keyId, 'tool' => $approval->tool, 'until' => gmdate(\DATE_ATOM, $until)]);
                } catch (RuleViolation) {
                    // The key went meanwhile: the yes is for this call only.
                    $until = null;
                }
            }
            $this->approvals->answer($approval, $decision === 'approve' || $decision === self::ALLOW, $until);
        }
        wp_safe_redirect($this->approvals->url($approval));
        exit;
    }

    private static function time(int $at): string
    {
        return (string) wp_date(\is_string($format = get_option('time_format')) ? $format : 'H:i', $at);
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
