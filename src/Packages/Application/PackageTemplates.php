<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * The templates the enabled extensions hold, for Twig: an extension's own under `x/<extension>/<path>`, and
 * overrides of the shop's templates by the extensions granted `templates`, under the shop's own names.
 */
interface PackageTemplates
{
    public const string PREFIX = 'x/';

    /** The source of the template with that name, or null when no enabled extension has it. */
    public function source(string $name): ?string;

    /** The extension whose template that is, for its words (design 57); null when no enabled extension has it. */
    public function owner(string $name): ?string;
}
