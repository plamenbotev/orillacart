<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Shared\Lifecycle\Languages;

/**
 * WordPress's locales: the visitor's (a logged-in user's profile language, or the site's), and switch_to_locale
 * for a while, which loads the plugin's and WordPress's translations of that language.
 */
final readonly class WordPressLanguages implements Languages
{
    public function current(): string
    {
        return determine_locale();
    }

    public function site(): string
    {
        return get_locale();
    }

    public function in(?string $locale, \Closure $work): mixed
    {
        $switched = switch_to_locale($locale ?? get_locale());
        try {
            return $work();
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }
}
