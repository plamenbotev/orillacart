<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterUnsubscribeLinks;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The unsubscribe link of the shop's emails: opening it asks to confirm (mail scanners open links, and must not
 * unsubscribe anyone), and confirming unsubscribes the email. A link not signed for the email does nothing.
 */
#[AsHook('template_redirect', priority: 1)]
final readonly class UnsubscribeFromLink
{
    public function __construct(
        private Newsletter $newsletter,
        private NewsletterUnsubscribeLinks $links,
    ) {}

    public function __invoke(): void
    {
        $email = $_GET[NewsletterUnsubscribeLinks::PARAMETER] ?? null;
        $signature = $_GET['sig'] ?? null;
        if (!\is_string($email) || !\is_string($signature)) {
            return;
        }
        $email = sanitize_email(rawurldecode(wp_unslash($email)));
        if ($email === '' || !$this->links->verify($email, sanitize_text_field(wp_unslash($signature)))) {
            wp_die(esc_html__('This unsubscribe link does not work. Unsubscribe in your account, or from the newsletter itself.', 'orillacart'), esc_html__('Unsubscribe', 'orillacart'), ['response' => 400]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->newsletter->unsubscribe($email);
            wp_die(esc_html(\sprintf(
                /* translators: %s: email address */
                __('%s is unsubscribed from our newsletter.', 'orillacart'),
                $email,
            )), esc_html__('Unsubscribed', 'orillacart'), ['response' => 200]);
        }
        wp_die(
            '<p>' . esc_html(\sprintf(
                /* translators: %s: email address */
                __('Unsubscribe %s from our newsletter?', 'orillacart'),
                $email,
            )) . '</p><form method="post"><button type="submit" class="button">' . esc_html__('Unsubscribe', 'orillacart') . '</button></form>',
            esc_html__('Unsubscribe', 'orillacart'),
            ['response' => 200],
        );
    }
}
