<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress\Blocks;

use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use Psr\Log\LoggerInterface;

/**
 * The Extension page block: a `page "/support" { template } fn(request)` declaration of an enabled extension
 * rendered on the page the editor put the block on, with the page's query string as the request.
 */
final readonly class ExtensionPageBlock implements BlockDefinition
{
    public function __construct(
        private PackageService $packages,
        private ScriptRunner $runner,
        private TemplateRenderer $templates,
        private LoggerInterface $logger,
    ) {}

    public static function name(): string
    {
        return 'orillacart/extension-page';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $slug = \is_string($attributes['extension'] ?? null) ? $attributes['extension'] : '';
        $path = '/' . trim(\is_string($attributes['path'] ?? null) ? $attributes['path'] : '', '/');
        foreach ($this->packages->loaded() as $loaded) {
            if ($loaded->package->slug() !== $slug) {
                continue;
            }
            foreach ($loaded->manifest->ofKind('page') as $declaration) {
                if ('/' . trim((string) $declaration['name'], '/') !== $path) {
                    continue;
                }
                $template = $declaration['props']['template'] ?? null;
                if (!\is_string($template)) {
                    return '';
                }
                $data = [];
                if ($declaration['handler'] !== null) {
                    try {
                        $answer = $this->runner->run($loaded, (string) $declaration['handler'], [self::request($path)]);
                    } catch (HandlerFailed $e) {
                        return $e->kind === HandlerFailed::REFUSED ? '<p>' . esc_html($e->getMessage()) . '</p>' : '';
                    }
                    $plain = \is_array($answer) ? Ops::plain($answer) : [];
                    foreach (\is_array($plain) ? $plain : [] as $key => $value) {
                        $data[(string) $key] = $value;
                    }
                }
                try {
                    return $this->templates->render(PackageTemplates::PREFIX . $slug . '/' . ltrim($template, '/'), $data + ['page' => $path]);
                } catch (\Throwable $e) {
                    $this->logger->warning('The extension "{extension}" could not render its page {page}: {message}', ['extension' => $slug, 'page' => $path, 'message' => $e->getMessage()]);

                    return '';
                }
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private static function request(string $path): array
    {
        $query = [];
        foreach ($_GET as $key => $value) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, a page's query string
            if (\is_string($key) && (\is_string($value) || \is_array($value))) {
                $query[$key] = \is_array($value) ? array_map(static fn(mixed $v): string => \is_string($v) ? sanitize_text_field(wp_unslash($v)) : '', $value) : sanitize_text_field(wp_unslash($value));
            }
        }
        $userId = get_current_user_id();

        return ['method' => 'GET', 'path' => $path, 'params' => [], 'query' => $query, 'body' => null, 'headers' => [], 'user' => ['id' => $userId > 0 ? $userId : null, 'logged_in' => $userId > 0, 'manager' => current_user_can('manage_orillacart')]];
    }
}
