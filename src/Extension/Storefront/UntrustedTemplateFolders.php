<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * Folders of storefront templates whose author the shop does not trust with PHP: a theme the shop's owner uploaded,
 * templates a site's editors write. Their templates are found before the theme's and the shop's own, by name as
 * those are ("storefront/account.html.twig"), and render as an extension's do: in the sandbox (the tags, filters
 * and functions the storefront allows, no methods or properties) and on its budget. Asked for each time the
 * storefront's templates are set up, so the folders may follow the active theme. Since version 24.
 *
 *     $registry->untrustedTemplates(new class implements UntrustedTemplateFolders {
 *         public function folders(): array { return [get_stylesheet_directory() . '/orillacart']; }
 *     });
 */
interface UntrustedTemplateFolders
{
    /**
     * Absolute paths, most specific first; a folder that is not there is left out.
     *
     * @return list<string>
     */
    public function folders(): array;
}
