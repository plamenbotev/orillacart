<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Languages;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;

/**
 * A site without Polylang or WPML: its content has the one language, and every post is only itself.
 */
final readonly class OneLanguage implements ContentLanguages
{
    public function multilingual(): bool
    {
        return false;
    }

    public function languages(): array
    {
        return [];
    }

    public function default(): ?Language
    {
        return null;
    }

    public function current(): ?Language
    {
        return null;
    }

    public function forLocale(string $locale): ?Language
    {
        return null;
    }

    public function ofPost(int $postId): ?string
    {
        return null;
    }

    public function postTranslations(int $postId): array
    {
        return [];
    }

    public function termTranslations(int $termId, string $taxonomy): array
    {
        return [];
    }
}
