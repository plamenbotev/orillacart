<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PreviewExtension;
use OrillaCart\Packages\Application\Dto\PreviewView;
use OrillaCart\Packages\Application\Dto\ScriptResponse;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\SlotContent;

/**
 * An extension's views rendered on demand (`POST /extensions/{id}/preview`, the assistant's preview_extension):
 * what a page, an admin page, a slot or an HTML route would show, for whoever writes the extension without
 * opening a browser. The handler runs as the shop runs it, as a manager, with the query, body or arguments
 * given; a refusal is the answer, an error what the screen would say.
 */
final readonly class PackagePreview
{
    public function __construct(
        private PackageService $packages,
        private ScriptRunner $runner,
        private TemplateRenderer $templates,
        private SlotContent $slots,
    ) {}

    /**
     * @throws PackageNotFound
     * @throws PackageRuleViolation for a view the extension does not declare, or one that fails
     */
    public function render(string $slug, PreviewExtension $command): PreviewView
    {
        $loaded = $this->loaded($slug);
        if ($command->kind === PreviewExtension::SLOT) {
            if (!\in_array($command->name, SlotContent::SLOTS, true)) {
                throw new PackageRuleViolation('There is no slot "%s"; the slots are %s.', 'orillacart_extension_no_such_view', values: [$command->name, implode(', ', SlotContent::SLOTS)]);
            }

            return new PreviewView($this->slots->render($command->name, $command->arguments), null);
        }
        if ($command->kind === PreviewExtension::ROUTE) {
            return $this->route($loaded, $command);
        }
        $path = '/' . trim($command->name, '/');
        foreach ($loaded->manifest->ofKind($command->kind) as $declaration) {
            if ('/' . trim((string) $declaration['name'], '/') !== $path) {
                continue;
            }
            $template = $declaration['props']['template'] ?? null;
            if (!\is_string($template)) {
                throw new PackageRuleViolation('The %s "%s" names no template.', 'orillacart_extension_no_such_view', values: [$command->kind, $path]);
            }
            $data = $declaration['handler'] === null ? [] : $this->handled($loaded, (string) $declaration['handler'], [self::request($path, $command->query, null)]);

            return new PreviewView($this->templates->render(PackageTemplates::PREFIX . $slug . '/' . ltrim($template, '/'), $data + ['page' => $path]), $data);
        }

        throw new PackageRuleViolation('The extension declares no %s "%s".', 'orillacart_extension_no_such_view', values: [$command->kind, $path]);
    }

    private function route(LoadedPackage $loaded, PreviewExtension $command): PreviewView
    {
        $split = preg_split('/\s+/', trim($command->name), 2);
        $parts = $split === false ? [] : $split;
        [$method, $path] = \count($parts) === 2 ? [strtoupper($parts[0]), $parts[1]] : ['GET', $parts[0] ?? '/'];
        $path = '/' . trim($path, '/');
        foreach ($loaded->manifest->ofKind('route') as $declaration) {
            if (strtoupper($declaration['words'][0] ?? 'GET') !== $method || '/' . trim((string) $declaration['name'], '/') !== $path || $declaration['handler'] === null) {
                continue;
            }
            $request = ['method' => $method] + self::request($path, $command->query, $method === 'GET' ? null : $command->body);
            try {
                $answer = $this->runner->run($loaded, (string) $declaration['handler'], [$request]);
            } catch (HandlerFailed $e) {
                throw new PackageRuleViolation('The handler %s: %s', 'orillacart_extension_view_failed', values: [$e->kind === HandlerFailed::REFUSED ? 'refused' : 'failed', $e->getMessage()]);
            }
            if ($answer instanceof ScriptResponse) {
                if ($answer->kind === ScriptResponse::HTML && $answer->template !== null) {
                    return new PreviewView($this->templates->render(PackageTemplates::PREFIX . $loaded->package->slug() . '/' . ltrim($answer->template, '/'), $answer->data ?? []), $answer->data);
                }

                return new PreviewView('', $answer->data ?? ['status' => $answer->status, 'message' => $answer->message, 'url' => $answer->url]);
            }
            $plain = Ops::plain($answer);

            return new PreviewView('', \is_array($plain) ? self::map($plain) : ['result' => $plain]);
        }

        throw new PackageRuleViolation('The extension declares no route "%s %s" (a path is written as declared, {id} and all; a route with parameters is called as a tool instead).', 'orillacart_extension_no_such_view', values: [$method, $path]);
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handled(LoadedPackage $loaded, string $method, array $args): array
    {
        try {
            $answer = $this->runner->run($loaded, $method, $args);
        } catch (HandlerFailed $e) {
            throw new PackageRuleViolation('The handler %s: %s', 'orillacart_extension_view_failed', values: [$e->kind === HandlerFailed::REFUSED ? 'refused' : 'failed', $e->getMessage()]);
        }
        $data = [];
        $plain = \is_array($answer) ? Ops::plain($answer) : [];
        foreach (\is_array($plain) ? $plain : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    private function loaded(string $slug): LoadedPackage
    {
        foreach ($this->packages->loaded() as $loaded) {
            if ($loaded->package->slug() === $slug) {
                return $loaded;
            }
        }
        $this->packages->package($slug);

        throw new PackageRuleViolation('The extension is not enabled: enable it to preview its views.', 'orillacart_extension_not_enabled');
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private static function request(string $path, array $query, ?array $body): array
    {
        return ['method' => 'GET', 'path' => $path, 'params' => [], 'query' => $query, 'body' => $body, 'headers' => [], 'user' => ['id' => null, 'logged_in' => true, 'manager' => true]];
    }
}
