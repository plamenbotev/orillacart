<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every registrar when the plugin loads.
 */
final readonly class WordPressIntegration
{
    /**
     * @param iterable<Registrar> $registrars
     */
    public function __construct(
        #[AutowireIterator(Registrar::TAG)]
        private iterable $registrars,
    ) {}

    public function register(): void
    {
        foreach ($this->registrars as $registrar) {
            $registrar->register();
        }
    }
}
