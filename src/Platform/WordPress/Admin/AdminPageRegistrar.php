<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\AdminPage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds the "Shop" admin menu from every AdminPage and loads each page's React app on its own screen only.
 */
final class AdminPageRegistrar implements Registrar
{
    public const string MENU_SLUG = 'orillacart';
    public const string ELEMENT_PREFIX = 'orillacart-admin-';

    /** @var array<string, AdminPage> screen hook suffix => page */
    private array $screens = [];

    /**
     * @param iterable<AdminPage> $pages
     */
    public function __construct(
        #[AutowireIterator(AdminPage::TAG)]
        private readonly iterable $pages,
        private readonly AdminScripts $scripts,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', $this->addMenu(...));
        add_action('admin_enqueue_scripts', $this->enqueue(...));
    }

    /** @return list<AdminPage> in menu order */
    public function pages(): array
    {
        $pages = [...$this->pages];
        usort($pages, static fn(AdminPage $a, AdminPage $b): int => [$a->menuOrder(), $a->slug()] <=> [$b->menuOrder(), $b->slug()]);

        return $pages;
    }

    private function addMenu(): void
    {
        $pages = $this->pages();
        if ($pages === []) {
            return;
        }

        // The top-level entry opens the first page; WordPress repeats it as the first submenu item.
        $first = $pages[0];
        add_menu_page(__('Shop', 'orillacart'), __('Shop', 'orillacart'), $first->capability(), self::MENU_SLUG, '', 'dashicons-cart', 56);

        foreach ($pages as $index => $page) {
            $slug = $index === 0 ? self::MENU_SLUG : self::MENU_SLUG . '-' . $page->slug();
            $hook = add_submenu_page(self::MENU_SLUG, $page->title(), $page->title(), $page->capability(), $slug, static function () use ($page): void {
                printf('<div class="wrap" id="%s"></div>', esc_attr(self::ELEMENT_PREFIX . $page->slug()));
            });
            if (\is_string($hook)) {
                $this->screens[$hook] = $page;
            }
        }
    }

    private function enqueue(string $hookSuffix): void
    {
        $page = $this->screens[$hookSuffix] ?? null;
        if ($page === null) {
            return;
        }

        $this->scripts->enqueue($page->script());
    }
}
