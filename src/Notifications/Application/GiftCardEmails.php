<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\GiftCards\Application\GiftCardMail;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * The email that brings a gift card to whom it is for. Sent once, when the card is made or its code replaced:
 * the shop keeps no code to send again. In the language of the order that bought it or was refunded with it; a
 * card the shop made by hand, in the site's: not in whatever language the shop manager's screen is in.
 */
final readonly class GiftCardEmails implements GiftCardMail
{
    public function __construct(
        private EmailWriter $writer,
        private Mailer $mailer,
        private EmailSettingsRepository $settings,
        private Site $site,
        private LoggerInterface $logger,
    ) {}

    public function send(string $to, string $code, string $amount, string $currency, ?string $expiresOn, string $recipientName, string $message, ?string $locale = null): bool
    {
        if (!$this->settings->load()->sends(EmailKind::GiftCard)) {
            return false;
        }
        try {
            $email = $this->writer->write(EmailKind::GiftCard->template(), EmailKind::GiftCard->value, $to, $locale, ['card' => [
                'code' => $code,
                'amount' => $amount,
                'currency' => $currency,
                'expires_at' => $expiresOn,
                'recipient_name' => $recipientName,
                'message' => $message,
                'shop_url' => $this->site->url(),
            ]]);
        } catch (EmailNotWritten $e) {
            $this->logger->error('The gift card email could not be written: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
        if (!$this->mailer->send($email)) {
            $this->logger->warning('The gift card email was not sent: the mail system refused it.');

            return false;
        }

        return true;
    }
}
