<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress\Hooks;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\ExtensionFrames;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;
use Psr\Log\LoggerInterface;

/**
 * `admin_page "/loyalty" { title: "Loyalty", template: "admin.html.twig" } fn(request)` (design 60): a screen
 * of the extension's own in the Shop menu, for the shop manager. Each enabled extension's pages are entries of
 * the menu, after the shop's own groups and before System, titled as declared; the screen is the extension's
 * template, rendered in the sandbox with what the handler answers, in a frame of its own (design 73,
 * ExtensionFrames): its scripts (`asset()`) run there, and call the extension's manager routes through the bridge,
 * `window.orillacart.api()`, which the admin's side answers with the nonce the frame never sees.
 */
#[AsHook('admin_menu', method: 'register', priority: 20)]
#[AsHook('admin_enqueue_scripts', method: 'enqueue')]
final class ScriptedAdminPages
{
    public const string KIND = 'admin_page';
    /** The Shop menu (AdminPageRegistrar::MENU_SLUG) and its System entry, which the extensions' screens go before. */
    public const string MENU = 'orillacart';
    private const string SYSTEM = self::MENU . '-status';
    /** admin.php?page=orillacart-x-<extension>-<path>. */
    public const string SLUG = self::MENU . '-x-';

    /** @var array<string, true> the screens added, by hook suffix */
    private array $screens = [];

    public function __construct(
        private readonly PackageService $packages,
        private readonly ScriptRunner $runner,
        private readonly TemplateRenderer $templates,
        private readonly ExtensionFrames $frame,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(): void
    {
        $position = self::beforeSystem();
        foreach ($this->pages() as [$loaded, $declaration, $path, $title]) {
            $slug = self::slug($loaded->package->slug(), $path);
            $hook = add_submenu_page(self::MENU, $title, $title, Capability::MANAGE_SHOP, $slug, fn() => $this->render($loaded, $declaration, $path, $title), $position);
            if (\is_string($hook)) {
                $this->screens[$hook] = true;
            }
            if ($position !== null) {
                ++$position;
            }
        }
    }

    /** The admin's side of the screen's frame, which answers its bridge. */
    public function enqueue(string $hookSuffix): void
    {
        if (isset($this->screens[$hookSuffix])) {
            $this->frame->enqueue();
        }
    }

    public static function slug(string $extension, string $path): string
    {
        return self::SLUG . $extension . ($path === '/' ? '' : '-' . trim(str_replace('/', '-', $path), '-'));
    }

    /**
     * The admin pages the enabled extensions declare, in the extensions' order.
     *
     * @return list<array{LoadedPackage, array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}, string, string}>
     */
    private function pages(): array
    {
        $pages = [];
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($loaded->manifest->ofKind(self::KIND) as $declaration) {
                $path = '/' . trim((string) $declaration['name'], '/');
                if (preg_match('#^(/[a-z0-9_-]+)*/?$#', $path) !== 1 || !\is_string($declaration['props']['template'] ?? null)) {
                    continue;
                }
                $title = \is_string($declaration['props']['title'] ?? null) && trim($declaration['props']['title']) !== '' ? trim($declaration['props']['title']) : $loaded->package->name();
                $pages[] = [$loaded, $declaration, $path, $title];
            }
        }

        return $pages;
    }

    /**
     * @param array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string} $declaration
     */
    private function render(LoadedPackage $loaded, array $declaration, string $path, string $title): void
    {
        printf('<div class="wrap orillacart-extension-page" data-extension="%s"><h1>%s</h1>', esc_attr($loaded->package->slug()), esc_html($title));
        $data = [];
        if ($declaration['handler'] !== null) {
            try {
                $answer = $this->runner->run($loaded, (string) $declaration['handler'], [self::request($path)]);
            } catch (HandlerFailed $e) {
                printf('<div class="notice notice-%s inline"><p>%s</p></div></div>', $e->kind === HandlerFailed::REFUSED ? 'warning' : 'error', esc_html($e->kind === HandlerFailed::REFUSED ? $e->getMessage() : __('The extension could not show this page; the Extensions screen says why.', 'orillacart')));

                return;
            }
            $plain = \is_array($answer) ? Ops::plain($answer) : [];
            foreach (\is_array($plain) ? $plain : [] as $key => $value) {
                $data[(string) $key] = $value;
            }
        }
        try {
            // The template is sandboxed and cleaned as printed; the frame keeps its scripts from the admin's page.
            $template = $declaration['props']['template'];
            $html = $this->templates->render(PackageTemplates::PREFIX . $loaded->package->slug() . '/' . ltrim(\is_string($template) ? $template : '', '/'), $data + ['page' => $path]);
            echo $this->frame->render($loaded->package->slug(), $html, $title); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the frame's document is escaped into srcdoc
        } catch (\Throwable $e) {
            $this->logger->warning('The extension "{extension}" could not render its admin page {page}: {message}', ['extension' => $loaded->package->slug(), 'page' => $path, 'message' => $e->getMessage()]);
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html__('The extension could not show this page; the Extensions screen says why.', 'orillacart'));
        }
        echo '</div>';
    }

    /**
     * The request as a route's handler would get it: the screen's query string, as the manager.
     *
     * @return array<string, mixed>
     */
    private static function request(string $path): array
    {
        $query = [];
        foreach ($_GET as $key => $value) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, a screen's query string
            if (\is_string($key) && $key !== 'page' && (\is_string($value) || \is_array($value))) {
                $query[$key] = \is_array($value) ? array_map(static fn(mixed $v): string => \is_string($v) ? sanitize_text_field(wp_unslash($v)) : '', $value) : sanitize_text_field(wp_unslash($value));
            }
        }
        $userId = get_current_user_id();

        return ['method' => 'GET', 'path' => $path, 'params' => [], 'query' => $query, 'body' => null, 'headers' => [], 'user' => ['id' => $userId > 0 ? $userId : null, 'logged_in' => $userId > 0, 'manager' => true]];
    }

    /** Where in the Shop submenu the System entry is, so the extensions' screens go before it; null to go last. */
    private static function beforeSystem(): ?int
    {
        $submenu = $GLOBALS['submenu'] ?? null;
        $items = \is_array($submenu) ? ($submenu[self::MENU] ?? null) : null;
        if (!\is_array($items)) {
            return null;
        }
        $position = 0;
        foreach ($items as $item) {
            if (\is_array($item) && ($item[2] ?? null) === self::SYSTEM) {
                return $position;
            }
            ++$position;
        }

        return null;
    }
}
