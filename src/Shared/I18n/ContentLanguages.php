<?php

declare(strict_types=1);

namespace OrillaCart\Shared\I18n;

/**
 * The languages of the site's content, when Polylang or WPML translates it: each translation of a post or a term
 * is a post or term of its own, linked to the others. Without either the site has one language and every post is
 * only itself.
 */
interface ContentLanguages
{
    /** Whether a plugin translates the site's content. */
    public function multilingual(): bool;

    /**
     * @return list<Language> in the plugin's order
     */
    public function languages(): array;

    public function default(): ?Language;

    /** The language this request shows content in. */
    public function current(): ?Language;

    /** The language whose locale this is, as an email in a customer's language needs. */
    public function forLocale(string $locale): ?Language;

    /** The code of the post's language, null when it has none. */
    public function ofPost(int $postId): ?string;

    /**
     * The post's translations, itself among them.
     *
     * @return array<string, int> post id by language code; empty when the post has no language
     */
    public function postTranslations(int $postId): array;

    /**
     * The term's translations, itself among them.
     *
     * @return array<string, int> term id by language code; empty when the term has no language
     */
    public function termTranslations(int $termId, string $taxonomy): array;
}
