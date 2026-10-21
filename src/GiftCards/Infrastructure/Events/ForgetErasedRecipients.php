<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A privacy request erased an email, from an order or anywhere: the gift cards made out to it forget the
 * recipient's name, email and message. The cards, and what is left on them, stay.
 */
final readonly class ForgetErasedRecipients
{
    public function __construct(private GiftCardService $cards) {}

    #[AsEventListener]
    public function personErased(PersonErased $event): void
    {
        $this->cards->forgetRecipient($event->email);
    }

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        $this->cards->forgetRecipient($event->email);
    }
}
