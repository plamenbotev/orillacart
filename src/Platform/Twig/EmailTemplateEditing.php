<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\View\TemplateEditing;
use OrillaCart\Shared\View\TemplateRejected;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Loader\FilesystemLoader;

/**
 * The email editor's view of the email templates: the files under emails/ (the plugin's, which a theme may
 * override), a check that an edit compiles and keeps to the sandbox, and previews of edits not saved.
 */
final readonly class EmailTemplateEditing implements TemplateEditing
{
    private const string FOLDER = 'emails';

    public function __construct(
        private PluginContext $context,
        private TwigFactory $files,
        private EditedTemplateLoader $edited,
        private Strictness $strictness,
        private Environment $twig,
    ) {}

    public function names(): array
    {
        $names = [];
        $files = glob($this->context->path(TwigFactory::PLUGIN_FOLDER . '/' . self::FOLDER . '/*.html.twig'));
        foreach ($files === false ? [] : $files as $file) {
            $names[] = self::FOLDER . '/' . basename($file);
        }
        sort($names);

        return $names;
    }

    public function fileSource(string $name): ?string
    {
        $files = new FilesystemLoader($this->files->templatePaths());

        return $files->exists($name) ? $files->getSourceContext($name)->getCode() : null;
    }

    public function check(string $name, string $source): void
    {
        try {
            $this->edited->withDrafts([$name => $source], function () use ($name): void {
                // Loading compiles it; rendering runs the sandbox's checks of its tags, filters and functions first.
                $template = $this->twig->load($name);
                try {
                    $template->render([]);
                } catch (RuntimeError) {
                    // It needs what an email gives it: the preview shows whether it has it.
                }
            });
        } catch (Error $e) {
            throw new TemplateRejected($e->getMessage(), $e);
        }
    }

    public function previewing(array $drafts, \Closure $work): mixed
    {
        return $this->strictness->during(fn(): mixed => $this->edited->withDrafts($drafts, $work));
    }
}
