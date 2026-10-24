<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Mailer;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * Sends through wp_mail(), so SMTP plugins and the site's mail settings apply, with the plain text as the HTML's
 * alternative, and the files the email carries (the invoice PDF) by their names. Add-ons add headers and attach
 * files through the filters orillacart_email_headers and orillacart_email_attachments, which get the email's kind
 * and order number.
 */
#[AsHook('phpmailer_init', method: 'alternative')]
#[AsHook('wp_mail_failed', method: 'failed')]
final class WordPressMailer implements Mailer
{
    /** The plain text of the mail being sent, for its alternative part. */
    private ?string $text = null;

    public function __construct(private readonly LoggerInterface $logger) {}

    /** Why the mail system refused one of the shop's mails, for the shop's problems: "SMTP connect() failed". */
    public function failed(mixed $error): void
    {
        if ($this->text !== null && $error instanceof \WP_Error) {
            $this->logger->error('An email could not be sent: {reason}', ['reason' => $error->get_error_message()]);
        }
    }

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
        // The email's own files go as files for the while, by their names.
        $made = [];
        foreach ($email->attachments as $attachment) {
            $path = tempnam(get_temp_dir(), 'orillacart-mail-');
            if ($path !== false && file_put_contents($path, $attachment->content) !== false) {
                $made[sanitize_file_name($attachment->filename)] = $path;
            }
        }

        $this->text = $email->text;
        try {
            return wp_mail($email->to, $email->subject, $email->html, $headers, $made + $attachments);
        } finally {
            $this->text = null;
            foreach ($made as $path) {
                wp_delete_file($path);
            }
        }
    }

    /** The plain text goes with the HTML as its alternative, for the shop's mails only. */
    public function alternative(object $mailer): void
    {
        if ($this->text !== null && property_exists($mailer, 'AltBody')) {
            $mailer->AltBody = $this->text;
        }
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $filtered): array
    {
        return \is_array($filtered) ? array_values(array_filter($filtered, static fn(mixed $line): bool => \is_string($line) && $line !== '')) : [];
    }
}
