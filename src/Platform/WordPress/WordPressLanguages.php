<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Shared\Lifecycle\Languages;

/**
 * WordPress's locales: the visitor's (a logged-in user's profile language, or the site's), and switch_to_locale
 * for a while, which loads the plugin's and WordPress's translations of that language. While it runs, the locale
 * is the visitor's also where WordPress has no files for it and switch_to_locale refuses it: the shop's own
 * translations and email templates (design 51) do not need WordPress's.
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
        $locale ??= get_locale();
        // A filter of its own, so that a switch inside this one comes before it and is undone alone.
        $current = static fn(): string => $locale;
        add_filter('pre_determine_locale', $current);
        $switched = switch_to_locale($locale);
        try {
            return $work();
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
            remove_filter('pre_determine_locale', $current);
        }
    }
}
