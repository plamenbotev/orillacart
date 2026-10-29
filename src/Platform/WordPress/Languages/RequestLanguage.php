<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\Lifecycle\Languages;

/**
 * The language of the page a storefront call comes from (design 51). The shop's REST requests carry no language
 * of Polylang's or WPML's: the page's URL has it, the request to /wp-json does not. The storefront sends the page's
 * locale, and the call is handled in it: the shop's words, the translated names, the order's language for its
 * emails. A locale the site does not offer is not followed.
 */
final readonly class RequestLanguage
{
    public const string HEADER = 'X-OrillaCart-Locale';

    public function __construct(
        private WordPressContentLanguages $content,
        private Languages $locales,
    ) {}

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function around(?string $locale, \Closure $work): mixed
    {
        $language = $locale === null || $locale === '' || !$this->content->multilingual() ? null : $this->content->forLocale($locale);
        if ($language === null) {
            return $work();
        }

        // WordPress's locale is the language's while the call runs, also where WordPress has no files for it
        // (switch_to_locale then refuses it): the order keeps it for its emails, the names are read in it.
        $locale = static fn(): string => $language->locale;
        add_filter('pre_determine_locale', $locale);
        try {
            return $this->locales->in($language->locale, fn(): mixed => $this->content->during($language, $work));
        } finally {
            remove_filter('pre_determine_locale', $locale);
        }
    }
}
