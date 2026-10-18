<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The version of the extension API. It changes only by adding: a new capability, method on a value object or
 * optional argument. An add-on can check it before registering what needs a newer API:
 *
 *     if ($registry->version() >= 2) { ... }
 */
final class Version
{
    public const int CURRENT = 1;
}
