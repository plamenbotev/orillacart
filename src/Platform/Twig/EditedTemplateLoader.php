<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\View\EditedTemplates;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Loads the email templates the shop edited, and drafts being previewed, before the files: the edit for the
 * language the email is written in (Languages::in), else the one for every language. A template compiles anew
 * whenever its source changes: the source is part of its cache key.
 */
final class EditedTemplateLoader implements LoaderInterface
{
    /** @var array<string, string> */
    private array $drafts = [];

    public function __construct(
        private readonly ?EditedTemplates $edited = null,
        private readonly ?Languages $languages = null,
    ) {}

    public function getSourceContext(string $name): Source
    {
        return new Source($this->source($name) ?? throw new LoaderError(\sprintf('Template "%s" is not edited.', $name)), $name);
    }

    public function getCacheKey(string $name): string
    {
        return 'edited:' . $name . ':' . hash('xxh128', $this->source($name) ?? '');
    }

    public function isFresh(string $name, int $time): bool
    {
        return true;
    }

    public function exists(string $name): bool
    {
        return $this->source($name) !== null;
    }

    /**
     * @internal for EmailTemplateEditing
     *
     * @template T
     *
     * @param array<string, string> $drafts
     * @param \Closure(): T         $work
     *
     * @return T
     */
    public function withDrafts(array $drafts, \Closure $work): mixed
    {
        $before = $this->drafts;
        $this->drafts = $drafts + $before;
        try {
            return $work();
        } finally {
            $this->drafts = $before;
        }
    }

    private function source(string $name): ?string
    {
        return $this->drafts[$name] ?? $this->edited?->edited($name, $this->languages?->current() ?? '');
    }
}
