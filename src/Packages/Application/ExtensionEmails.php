<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Notifications\Emails;
use OrillaCart\Notifications\Application\EmailNotWritten;
use OrillaCart\Notifications\Application\EmailWriter;
use OrillaCart\Notifications\Application\Mailer;
use Psr\Log\LoggerInterface;

/**
 * Email from add-ons (design 61): the body, cleaned as a product block is, inside the shop's email layout, from
 * the shop's address, in the site's language; sent as the shop's own emails are sent.
 */
final readonly class ExtensionEmails implements Emails
{
    public const string TEMPLATE = 'emails/extension.html.twig';
    public const string KIND = 'extension';
    public const int MAX_SUBJECT = 200;
    public const int MAX_BODY = 64 * 1024;

    public function __construct(
        private EmailWriter $writer,
        private Mailer $mailer,
        private HtmlCleaner $cleaner,
        private LoggerInterface $logger,
    ) {}

    public function send(string $to, string $subject, string $html, ?string $replyTo = null): bool
    {
        $to = trim($to);
        if (filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an email address.', $to));
        }
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);
        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) {
            throw new \InvalidArgumentException(\sprintf('An email\'s subject is one line, up to %d characters.', self::MAX_SUBJECT));
        }
        if (\strlen($html) > self::MAX_BODY) {
            throw new \InvalidArgumentException(\sprintf('An email\'s body is at most %d KB.', intdiv(self::MAX_BODY, 1024)));
        }
        if ($replyTo !== null && filter_var(trim($replyTo), \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an email address to reply to.', $replyTo));
        }
        try {
            $email = $this->writer->write(self::TEMPLATE, self::KIND, $to, null, ['subject' => $subject, 'body' => $this->cleaner->clean($html)], $replyTo === null ? null : trim($replyTo));
        } catch (EmailNotWritten $e) {
            $this->logger->error('An extension\'s email could not be written: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
        if (!$this->mailer->send($email)) {
            $this->logger->warning('An extension\'s email to {to} was not sent: the mail system refused it.', ['to' => $to]);

            return false;
        }

        return true;
    }
}
