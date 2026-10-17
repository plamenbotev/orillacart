<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wires one kind of WordPress job to the classes that do it. Registrars are the only code
 * that calls add_action and add_filter (PHPStan enforces this); they run once per request at plugin load.
 */
#[AutoconfigureTag(self::TAG)]
interface Registrar
{
    public const string TAG = 'orillacart.registrar';

    public function register(): void;
}
