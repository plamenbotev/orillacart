<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/** Makes HTML a script rendered safe for the storefront: scripts, handlers and the like taken out. */
interface HtmlCleaner
{
    public function clean(string $html): string;
}
