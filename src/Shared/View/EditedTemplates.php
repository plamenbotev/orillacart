<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

/**
 * The email templates the shop wrote in the admin, by name ("emails/order-shipped.html.twig"). Each comes before
 * the theme's file of the name and the plugin's; one for the reader's language before the one for every language.
 */
interface EditedTemplates
{
    /** The shop's own source of the template in the locale (de_DE); null when the files give it. */
    public function edited(string $name, string $locale): ?string;
}
