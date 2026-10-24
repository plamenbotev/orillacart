<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Marketing;

use OrillaCart\Extension\ProviderUnavailable;

/**
 * A mailing list whose service can delete a contact, not only unsubscribe them: a person erased on a privacy
 * request is deleted there too, from every list the shop knows, the ones it no longer sends sign-ups to included.
 * A list without it is told to unsubscribe them. Since version 12.
 */
interface ForgetsSubscribers extends MailingList
{
    /**
     * Deletes the contact and what the service keeps of them.
     *
     * @throws ProviderUnavailable when the service cannot be reached now: the shop tries again later
     */
    public function forget(string $email): void;
}
