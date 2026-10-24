<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Newsletter;

use OrillaCart\Extension\Marketing\ForgetsSubscribers;
use OrillaCart\Extension\Marketing\Subscriber;

/** A mailing list whose service deletes contacts; it keeps who it was asked to forget. */
final class ForgettingMailingList implements ForgetsSubscribers
{
    /** @var list<string> */
    public array $forgotten = [];

    public function id(): string
    {
        return 'forgetting_list';
    }

    public function name(): string
    {
        return 'Forgetting list';
    }

    public function subscribe(Subscriber $subscriber): void {}

    public function unsubscribe(string $email): void {}

    public function forget(string $email): void
    {
        $this->forgotten[] = $email;
    }
}
