<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Events;

use OrillaCart\Platform\Doctrine\AfterCommit;
use OrillaCart\Shared\Domain\Event\PublicEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Announces public domain events to WordPress as "orillacart_*" actions, after the plugin's own
 * listeners ran and once the transaction they happened in committed, so an add-on never acts on an order the
 * database then rolls back. This is the one place domain events leave the plugin.
 */
#[AsDecorator('event_dispatcher')]
final readonly class WordPressActionBridge implements EventDispatcherInterface
{
    public const string PREFIX = 'orillacart_';

    public function __construct(
        #[AutowireDecorated]
        private EventDispatcherInterface $inner,
        private AfterCommit $afterCommit,
    ) {}

    public function dispatch(object $event): object
    {
        $event = $this->inner->dispatch($event);

        $stopped = $event instanceof StoppableEventInterface && $event->isPropagationStopped();
        if ($event instanceof PublicEvent && !$stopped) {
            $hook = self::PREFIX . $event->hookName();
            $arguments = $event->hookArguments();
            $this->afterCommit->defer(static function () use ($hook, $arguments): void {
                do_action($hook, ...$arguments);
            });
        }

        return $event;
    }
}
