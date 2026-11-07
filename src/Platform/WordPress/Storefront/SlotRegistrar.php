<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Storefront;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\SlotContent;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Hooks every storefront slot once; what is printed is asked of the slot contents when the slot renders. */
final readonly class SlotRegistrar implements Registrar
{
    /**
     * @param iterable<SlotContent> $contents
     */
    public function __construct(
        #[AutowireIterator(SlotContent::TAG)]
        private iterable $contents,
    ) {}

    public function register(): void
    {
        foreach (SlotContent::SLOTS as $slot) {
            add_action('orillacart_' . $slot, function (mixed ...$arguments) use ($slot): void {
                foreach ($this->contents as $content) {
                    echo $content->render($slot, array_values($arguments));
                }
            }, 10, 5);
        }
    }
}
