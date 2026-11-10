<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Ordering\Domain\OrderRepository;
use Psr\Log\LoggerInterface;

/**
 * What stands between the shop's emails and the mail system (design 62): an email to an address that can never
 * be delivered (the domains reserved for examples and tests), about a test order, or to a customer made to try
 * the shop out is not sent. It is logged, and answered as sent: nothing failed, so nothing is retried or shown
 * as a problem.
 */
final readonly class GuardedMailer implements Mailer
{
    /** RFC 2606 and RFC 6761: names that are never anyone's mailbox. */
    private const array RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];
    private const array RESERVED_ENDINGS = ['.example', '.invalid', '.localhost', '.test'];

    public function __construct(
        private Mailer $mailer,
        private Undeliverable $undeliverable,
        private OrderRepository $orders,
        private Accounts $accounts,
        private LoggerInterface $logger,
    ) {}

    public function send(Email $email): bool
    {
        $why = $this->held($email);
        if ($why !== null) {
            $this->logger->info('The {kind} email to {to} was not sent: {why}.', ['kind' => $email->kind, 'to' => $email->to, 'why' => $why]);

            return true;
        }

        return $this->mailer->send($email);
    }

    /** Why the email is not sent; null when it goes. */
    private function held(Email $email): ?string
    {
        if ($this->undeliverable->address($email->to)) {
            return 'the address is one no email goes to (reserved for examples and tests, or the site\'s own)';
        }
        if ($email->orderNumber !== null && $this->orders->find($email->orderNumber)?->isTest() === true) {
            return 'it is about a test order';
        }
        if ($this->accounts->isTestEmail($email->to)) {
            return 'the customer was made to try the shop out';
        }

        return null;
    }

    /** Whether the address is at a name reserved for examples and tests, which no mail can reach. */
    public static function reserved(string $address): bool
    {
        $at = strrpos($address, '@');
        $domain = rtrim(mb_strtolower(trim($at === false ? $address : substr($address, $at + 1))), '.>');
        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
                return true;
            }
        }
        foreach (self::RESERVED_ENDINGS as $ending) {
            if (str_ends_with($domain, $ending) || $domain === ltrim($ending, '.')) {
                return true;
            }
        }

        return false;
    }
}
