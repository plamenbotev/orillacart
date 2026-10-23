<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Customers\Application\AccountLinks;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\ExemptionMail;
use Psr\Log\LoggerInterface;

/**
 * The emails about tax exemption certificates: to the shop when one comes, to the customer when the shop checked
 * it and a month before it runs out.
 */
final readonly class ExemptionEmails implements ExemptionMail
{
    public function __construct(
        private EmailWriter $writer,
        private Mailer $mailer,
        private EmailSettingsRepository $settings,
        private Languages $languages,
        private AccountLinks $links,
        private LoggerInterface $logger,
    ) {}

    public function received(TaxExemptionView $exemption, string $customerName, string $customerEmail): void
    {
        $this->send(EmailKind::ExemptionReceived, $this->writer->shopAddress(), null, ['customer' => ['name' => $customerName, 'email' => $customerEmail], 'exemption' => self::context($exemption)], $customerEmail);
    }

    public function reviewed(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->send(EmailKind::ExemptionReviewed, $email, $this->languages->current(), ['first_name' => $firstName, 'account_url' => $this->links->accountUrl(), 'exemption' => self::context($exemption)]);
    }

    public function expiring(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->send(EmailKind::ExemptionExpiring, $email, $this->languages->current(), ['first_name' => $firstName, 'account_url' => $this->links->accountUrl(), 'exemption' => self::context($exemption)]);
    }

    /** @return array<string, mixed> */
    private static function context(TaxExemptionView $exemption): array
    {
        return ['certificate' => $exemption->certificate, 'regions' => $exemption->regions, 'expires_on' => $exemption->expiresOn, 'status' => $exemption->status, 'refusal' => $exemption->refusal, 'reason' => $exemption->reason];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(EmailKind $kind, string $to, ?string $locale, array $context, ?string $replyTo = null): void
    {
        if (!$this->settings->load()->sends($kind)) {
            return;
        }
        try {
            $email = $this->writer->write($kind->template(), $kind->value, $to, $locale, $context, $replyTo);
        } catch (EmailNotWritten $e) {
            $this->logger->error('The {kind} email could not be written: {message}', ['kind' => $kind->value, 'message' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        if (!$this->mailer->send($email)) {
            $this->logger->warning('The {kind} email was not sent: the mail system refused it.', ['kind' => $kind->value]);
        }
    }
}
