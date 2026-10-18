<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A WP-CLI command: its public methods are the subcommands, documented in their docblocks the way WP-CLI reads
 * them. Registered only when WP-CLI runs.
 */
#[AutoconfigureTag(self::TAG)]
interface CliCommand
{
    public const string TAG = 'orillacart.cli_command';

    /** The command, such as "orillacart index". */
    public static function name(): string;
}
