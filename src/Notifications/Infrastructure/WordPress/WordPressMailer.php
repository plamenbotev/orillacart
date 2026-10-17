<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Mailer;

/**
 * Sends through wp_mail(), so SMTP plugins and the site's mail settings apply.
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

        return wp_mail($email->to, $email->subject, $email->html, $headers);
    }
}
