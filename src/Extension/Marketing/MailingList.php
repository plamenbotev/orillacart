<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Marketing;

use OrillaCart\Extension\ProviderUnavailable;

/**
 * A mailing list at a newsletter service (Mailchimp, Brevo, …) that the shop's newsletter sign-ups go to. The
 * shop keeps every sign-up itself; the shop manager chooses which lists also get them (Shop → Customers →
 * Newsletter), and the shop tells each one in the background, again later when the service could not be
 * reached. Since version 6.
 */
interface MailingList
{
    /** Lowercase letters, digits and underscores, up to 40; unique among mailing lists. */
    public function id(): string;

    /** As the shop manager chooses it: "Mailchimp: Customers". */
    public function name(): string;

    /**
     * Someone signed up, or signed up again: they are on the list from now on.
     *
     * @throws ProviderUnavailable when the service cannot be reached now: the shop tries again later
     */
    public function subscribe(Subscriber $subscriber): void;

    /**
     * Someone unsubscribed at the shop, or the shop erased them: they are off the list.
     *
     * @throws ProviderUnavailable when the service cannot be reached now: the shop tries again later
     */
    public function unsubscribe(string $email): void;
}
