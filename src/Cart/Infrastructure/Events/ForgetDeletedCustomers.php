<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Infrastructure\Events;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Domain\CartRepository;
use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Forms\Application\FormUploads;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A customer deleted or erased takes their cart with them, and the files its lines name that no order took: at
 * once, not after the week a held file waits.
 */
final readonly class ForgetDeletedCustomers
{
    public function __construct(
        private CartRepository $carts,
        private AbandonedCarts $abandoned,
        private FormUploads $uploads,
    ) {}

    #[AsEventListener]
    public function __invoke(CustomerDeleted $event): void
    {
        $this->forget($event->customerId);
        $this->abandoned->forgetCustomer($event->customerId);
    }

    /** Erased, as a privacy request asked: their cart goes too. */
    #[AsEventListener]
    public function erased(CustomerErased $event): void
    {
        $this->forget($event->customerId);
    }

    private function forget(int $customerId): void
    {
        $cart = $this->carts->findByCustomer($customerId);
        if ($cart !== null) {
            $this->uploads->forgetNamed($cart->fileHashes());
        }
        $this->carts->removeOfCustomer($customerId);
    }

    /** A privacy request erased an email: the carts left at checkout forget it. */
    #[AsEventListener]
    public function personErased(PersonErased $event): void
    {
        $this->abandoned->forgetEmail($event->email);
    }
}
