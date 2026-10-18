<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Mailer;

/**
 * Sends through wp_mail(), so SMTP plugins and the site's mail settings apply. Add-ons add headers and attach
 * files (an invoice, say) through the filters orillacart_email_headers and orillacart_email_attachments, which get
 * the email's kind and order number.
 */
final readonly class WordPressMailer implements Mailer
{
    public function send(Email $email): bool
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            \sprintf('From: %s <%s>', $email->fromName, $email->fromAddress),
        ];
        if ($email->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $email->replyTo;
        }
        $headers = self::lines(apply_filters('orillacart_email_headers', $headers, $email->kind, $email->orderNumber));
        $attachments = array_values(array_filter(self::lines(apply_filters('orillacart_email_attachments', [], $email->kind, $email->orderNumber)), is_file(...)));

        return wp_mail($email->to, $email->subject, $email->html, $headers, $attachments);
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $filtered): array
    {
        return \is_array($filtered) ? array_values(array_filter($filtered, static fn(mixed $line): bool => \is_string($line) && $line !== '')) : [];
    }
}
