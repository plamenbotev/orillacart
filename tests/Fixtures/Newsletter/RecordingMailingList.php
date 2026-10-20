<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Newsletter;

use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Marketing\Subscriber;
use OrillaCart\Extension\ProviderUnavailable;

/** A mailing list that keeps what it heard, and is down while asked to be. */
final class RecordingMailingList implements MailingList
{
    /** @var list<string> "+ada@example.com" for a sign-up, "-ada@example.com" for leaving */
    public array $heard = [];
    /** @var array<string, Subscriber> */
    public array $members = [];
    public bool $down = false;

    public function __construct(private readonly string $id = 'test_list') {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return 'Test list';
    }

    public function subscribe(Subscriber $subscriber): void
    {
        if ($this->down) {
            throw new ProviderUnavailable('Test list', 'down');
        }
        $this->heard[] = '+' . $subscriber->email;
        $this->members[$subscriber->email] = $subscriber;
    }

    public function unsubscribe(string $email): void
    {
        if ($this->down) {
            throw new ProviderUnavailable('Test list', 'down');
        }
        $this->heard[] = '-' . $email;
        unset($this->members[$email]);
    }
}
