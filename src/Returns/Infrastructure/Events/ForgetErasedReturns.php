<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Returns\Domain\ReturnRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A return keeps the items, the reason and the money of an order whose customer was erased, not the words written
 * with it: the customer's comment and the shop's answer to them.
 */
final readonly class ForgetErasedReturns
{
    public function __construct(private ReturnRepository $returns) {}

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        foreach ($this->returns->forOrder($event->orderId) as $return) {
            if ($return->comment() !== '' || $return->answer() !== '') {
                $return->forgetWords();
                $this->returns->save($return);
            }
        }
    }
}
