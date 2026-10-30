<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterConfirmLinks;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The link that confirms a newsletter sign-up: opening it asks to confirm (mail scanners open links, and must not
 * sign anyone up), and confirming puts the email on the newsletter. A link not signed for the email does nothing.
 */
#[AsHook('template_redirect', priority: 1)]
final readonly class ConfirmFromLink
{
    public function __construct(
        private Newsletter $newsletter,
        private NewsletterConfirmLinks $links,
    ) {}

    public function __invoke(): void
    {
        $email = $_GET[NewsletterConfirmLinks::PARAMETER] ?? null;
        $signature = $_GET['sig'] ?? null;
        if (!\is_string($email) || !\is_string($signature)) {
            return;
        }
        $email = sanitize_email(rawurldecode(wp_unslash($email)));
        if ($email === '' || !$this->links->verify($email, sanitize_text_field(wp_unslash($signature)))) {
            wp_die(esc_html__('This confirmation link does not work. Sign up again for a new one.', 'orillacart'), esc_html__('Newsletter', 'orillacart'), ['response' => 400]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $confirmed = $this->newsletter->confirm($email) || $this->newsletter->isSubscribed($email);
            wp_die(esc_html($confirmed
                ? \sprintf(
                    /* translators: %s: email address */
                    __('%s is signed up for our newsletter. Thank you.', 'orillacart'),
                    $email,
                )
                : __('This sign-up was withdrawn since. Sign up again to get the newsletter.', 'orillacart')), esc_html__('Newsletter', 'orillacart'), ['response' => 200]);
        }
        wp_die(
            '<p>' . esc_html(\sprintf(
                /* translators: %s: email address */
                __('Sign %s up for our newsletter?', 'orillacart'),
                $email,
            )) . '</p><form method="post"><button type="submit" class="button">' . esc_html__('Confirm my sign-up', 'orillacart') . '</button></form>',
            esc_html__('Newsletter', 'orillacart'),
            ['response' => 200],
        );
    }
}
