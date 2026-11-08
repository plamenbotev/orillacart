<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * The shop's own templates, as the theme or the plugin ships them: what an extension's `templates/<name>` file
 * overrides. An override remembers the original's hash when saved and is marked stale once a release changes it.
 */
interface PluginTemplates
{
    /** The template's source as the shop would render it without extensions, or null for a name the shop has not. */
    public function source(string $name): ?string;

    public static function hash(string $source): string;
}
