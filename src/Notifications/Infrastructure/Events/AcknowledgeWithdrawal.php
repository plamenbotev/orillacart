<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\Events;

use OrillaCart\Notifications\Application\WithdrawalEmails;
use OrillaCart\Returns\Domain\Event\WithdrawalDeclared;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class AcknowledgeWithdrawal
{
    public function __construct(private WithdrawalEmails $emails) {}

    #[AsEventListener]
    public function declared(WithdrawalDeclared $event): void
    {
        $this->emails->acknowledge($event);
    }
}
