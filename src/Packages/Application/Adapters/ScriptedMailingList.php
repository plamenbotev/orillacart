<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Marketing\ForgetsSubscribers;
use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Marketing\Subscriber;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * `mailing_list "id" { name; fn subscribe(subscriber); fn unsubscribe(email); fn forget(email) }`: a list the
 * shop's newsletter signs people up to, in a background job that tries again when the script says unavailable.
 */
final class ScriptedMailingList extends ScriptedProvider implements MailingList, ForgetsSubscribers
{
    public function name(): string
    {
        return $this->title();
    }

    public function subscribe(Subscriber $subscriber): void
    {
        $this->tell('subscribe', [Values::record($subscriber)]);
    }

    public function unsubscribe(string $email): void
    {
        $this->tell('unsubscribe', [$email]);
    }

    public function forget(string $email): void
    {
        if ($this->has('forget')) {
            $this->tell('forget', [$email]);
        }
    }

    /**
     * @param list<mixed> $args
     */
    private function tell(string $function, array $args): void
    {
        try {
            $this->call($function, $args);
        } catch (HandlerFailed $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
