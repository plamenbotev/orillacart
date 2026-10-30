<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Customers\Application\AccountLinks;
use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\ExemptionMail;
use Psr\Log\LoggerInterface;

/**
 * The emails about tax exemption certificates: to the shop when one comes, to the customer when the shop checked
 * it and a month before it runs out, in the customer's language.
 */
final readonly class ExemptionEmails implements ExemptionMail
{
    /** A person's orders come oldest first: pages of them read to find the latest, at most so many. */
    private const int ORDERS_PER_PAGE = 100;
    private const int ORDER_PAGES = 10;

    public function __construct(
        private EmailWriter $writer,
        private Mailer $mailer,
        private EmailSettingsRepository $settings,
        private AccountLinks $links,
        private LoggerInterface $logger,
        private Accounts $accounts,
        private OrderService $orders,
    ) {}

    public function received(TaxExemptionView $exemption, string $customerName, string $customerEmail): void
    {
        $this->send(EmailKind::ExemptionReceived, $this->writer->shopAddress(), null, ['customer' => ['name' => $customerName, 'email' => $customerEmail], 'exemption' => self::context($exemption)], $customerEmail);
    }

    public function reviewed(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->send(EmailKind::ExemptionReviewed, $email, $this->localeOf($exemption->customerId, $email), ['first_name' => $firstName, 'account_url' => $this->links->accountUrl(), 'exemption' => self::context($exemption)]);
    }

    public function expiring(TaxExemptionView $exemption, string $email, string $firstName): void
    {
        $this->send(EmailKind::ExemptionExpiring, $email, $this->localeOf($exemption->customerId, $email), ['first_name' => $firstName, 'account_url' => $this->links->accountUrl(), 'exemption' => self::context($exemption)]);
    }

    /**
     * The language the customer reads: that of their latest order, else the one their profile names; null for the
     * site's. Never the shop manager's, who reviewed it, nor the job's.
     */
    private function localeOf(int $customerId, string $email): ?string
    {
        $latest = null;
        for ($page = 1; $page <= self::ORDER_PAGES; ++$page) {
            $orders = $this->orders->ofPerson($email, $customerId, $page, self::ORDERS_PER_PAGE);
            if ($orders !== []) {
                $latest = $orders[array_key_last($orders)];
            }
            if (\count($orders) < self::ORDERS_PER_PAGE) {
                break;
            }
        }

        return $latest->locale ?? $this->accounts->find($customerId)?->locale;
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
