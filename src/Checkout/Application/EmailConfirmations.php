<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Shared\Domain\EmailAddress;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * A guest's email confirmed by a link sent to it, for the place that holds as many orders waiting for a transfer
 * as one shopper may (CheckoutService::placeOrder()): whoever follows the link has that mailbox, so their orders
 * are counted by it and not by the address they share with others.
 *
 * The link is followed wherever the mail is read, a phone on another network as well as the browser that orders:
 * it carries a key of the mailbox (EmailAddress::key(): ada+shop@ is ada@) and of the cart that asked, which names
 * neither, when it was asked for, and the shop's signature of both. Confirmed, the shop keeps the key for what is
 * left of VALID_HOURS; the browser with that cart then orders in that email. Another browser typing the same
 * email has another cart, and is asked itself.
 */
final readonly class EmailConfirmations
{
    /** The job that emails the link (SendEmailConfirmation). */
    public const string LINK_JOB = 'mail.email_confirmation';
    public const int VALID_HOURS = 24;
    /** What a token looks like. */
    public const string PATTERN = '\\d{1,12}\\.[a-f0-9]{32}\\.[a-f0-9]{32}';
    private const string KEY = 'email-confirmation-key';
    private const string SIGNATURE = 'email-confirmation';

    public function __construct(
        private Signatures $signatures,
        private Jobs $jobs,
        private ClockInterface $clock,
        private ConfirmedEmails $confirmed,
    ) {}

    /**
     * Emails the address the link that confirms it for the cart.
     *
     * @param string $locale the language the guest orders in
     */
    public function send(string $email, #[\SensitiveParameter] string $cart, string $locale): void
    {
        $this->jobs->push(self::LINK_JOB, ['email' => $email, 'token' => $this->token($email, $cart, $this->clock->now()->getTimestamp()), 'locale' => $locale], 'email-confirmation:' . $this->key($email, $cart));
    }

    /** The token of a link: when it was asked for, the key of the mailbox and the cart, and the signature of both. */
    public function token(string $email, #[\SensitiveParameter] string $cart, int $askedAt): string
    {
        $key = $this->key($email, $cart);

        return "{$askedAt}.{$key}." . $this->signatures->sign(self::SIGNATURE, "{$key}|{$askedAt}");
    }

    /**
     * The link was followed: the email is confirmed for the cart that asked, for what is left of VALID_HOURS.
     *
     * @throws NotFound for a link that is not one the shop made, or is too old
     */
    public function confirm(#[\SensitiveParameter] string $token): void
    {
        $parts = preg_match('/^' . self::PATTERN . '$/', $token) === 1 ? explode('.', $token) : [];
        $askedAt = (int) ($parts[0] ?? 0);
        $age = $this->clock->now()->getTimestamp() - $askedAt;
        if (\count($parts) !== 3 || $age < 0 || $age > self::VALID_HOURS * 3600 || !$this->signatures->verify(self::SIGNATURE, "{$parts[1]}|{$askedAt}", $parts[2])) {
            throw new NotFound('This link does not work any more: place the order again for a new one.', 'orillacart_email_link_invalid');
        }
        $this->confirmed->keep($parts[1], $askedAt + self::VALID_HOURS * 3600);
    }

    /** Whether the email was confirmed for the cart, and still is. */
    public function confirmed(string $email, #[\SensitiveParameter] ?string $cart): bool
    {
        $until = $cart === null ? null : $this->confirmed->until($this->key($email, $cart));

        return $until !== null && $until >= $this->clock->now()->getTimestamp();
    }

    /** The shopper a confirmed email is, as the limits name callers: a mailbox, not the address as written. */
    public static function shopper(string $email): string
    {
        return 'email:' . hash('sha256', EmailAddress::key($email));
    }

    private function key(string $email, #[\SensitiveParameter] string $cart): string
    {
        return $this->signatures->sign(self::KEY, EmailAddress::key($email) . '|' . $cart);
    }
}
