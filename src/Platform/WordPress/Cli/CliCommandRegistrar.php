<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Cli;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\CliCommand;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Adds the plugin's WP-CLI commands when WP-CLI runs; a command is built only then.
 */
final readonly class CliCommandRegistrar implements Registrar
{
    /**
     * @param ServiceLocator<CliCommand> $commands by name
     */
    public function __construct(
        #[AutowireLocator(CliCommand::TAG, defaultIndexMethod: 'name')]
        private ServiceLocator $commands,
    ) {}

    public function register(): void
    {
        if (!\defined('WP_CLI') || !WP_CLI) {
            return;
        }
        foreach (array_keys($this->commands->getProvidedServices()) as $name) {
            \WP_CLI::add_command($name, $this->commands->get($name));
        }
    }
}
