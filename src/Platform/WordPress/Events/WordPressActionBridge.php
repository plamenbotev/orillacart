<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Events;

use OrillaCart\Shared\Domain\Event\PublicEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Announces public domain events to WordPress as "orillacart_*" actions, after the plugin's own
 * listeners ran. This is the one place domain events leave the plugin.
 */
#[AsDecorator('event_dispatcher')]
final readonly class WordPressActionBridge implements EventDispatcherInterface
{
    public const string PREFIX = 'orillacart_';

    public function __construct(
        #[AutowireDecorated]
        private EventDispatcherInterface $inner,
    ) {}

    public function dispatch(object $event): object
    {
        $event = $this->inner->dispatch($event);

        $stopped = $event instanceof StoppableEventInterface && $event->isPropagationStopped();
        if ($event instanceof PublicEvent && !$stopped) {
            do_action(self::PREFIX . $event->hookName(), ...$event->hookArguments());
        }

        return $event;
    }
}
