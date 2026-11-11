<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Templates from folders the shop does not trust with PHP (extension API 24, UntrustedTemplateFolders): a source's
 * path is marked as theirs, so the sandbox and the budget are on for it as for an extension's; and its compiled
 * form is cached apart from a trusted template of the same file, so a template compiled outside the sandbox is never
 * run as one of these. The folders are asked for at each lookup, not when the storefront's Twig is made: by then
 * every add-on has hooked in, and they follow the active theme.
 */
final class UntrustedTemplateLoader implements LoaderInterface
{
    private const string MARK = 'orillacart-untrusted:';

    private ?FilesystemLoader $files = null;

    /** @var list<string> the folders $files reads */
    private array $current = [];

    /** @param \Closure(): list<string> $folders */
    public function __construct(private readonly \Closure $folders) {}

    public function getSourceContext(string $name): Source
    {
        $source = $this->files()?->getSourceContext($name) ?? throw new LoaderError(\sprintf('No untrusted folder has the template "%s".', $name));

        return new Source($source->getCode(), $source->getName(), self::MARK . $source->getPath());
    }

    public function getCacheKey(string $name): string
    {
        return 'untrusted:' . ($this->files()?->getCacheKey($name) ?? throw new LoaderError(\sprintf('No untrusted folder has the template "%s".', $name)));
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->files()?->isFresh($name, $time) ?? false;
    }

    public function exists(string $name): bool
    {
        return $this->files()?->exists($name) ?? false;
    }

    /** Whether a source's path is one of these templates'. */
    public static function isUntrusted(string $path): bool
    {
        return str_starts_with($path, self::MARK);
    }

    /** The folders as they are now: they follow the active theme, which a request may change (a preview). */
    private function files(): ?FilesystemLoader
    {
        $folders = ($this->folders)();
        if ($folders !== $this->current) {
            $this->current = $folders;
            $this->files = $folders === [] ? null : new FilesystemLoader($folders);
        }

        return $this->files;
    }
}
