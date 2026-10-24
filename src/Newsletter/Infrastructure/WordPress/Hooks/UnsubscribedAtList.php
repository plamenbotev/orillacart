<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Infrastructure\WordPress\Hooks;

use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A mailing list's add-on heard from its service that someone unsubscribed there (its own link, a complaint):
 * do_action('orillacart_newsletter_unsubscribed', $email, $listId). The shop takes them off its newsletter too,
 * so no list signs them up again.
 */
#[AsHook('orillacart_newsletter_unsubscribed', arguments: 2)]
final readonly class UnsubscribedAtList
{
    public function __construct(private Newsletter $newsletter) {}

    public function __invoke(mixed $email, mixed $listId = null): void
    {
        if (\is_string($email) && is_email($email) !== false) {
            $this->newsletter->unsubscribedAtList($email);
        }
    }
}
