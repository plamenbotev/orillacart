<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\AdminPage;
use OrillaCart\Shared\WordPress\MenuGroup;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds the "Shop" admin menu from every AdminPage and loads each page's React app on its own screen only.
 *
 * Design 52: the menu has one entry per MenuGroup, which opens the group's first page; the group's other pages
 * are reached by the tabs the screen shows (the group and its tabs are handed to the script as
 * window.orillacartAdmin) and stay out of the menu. Hidden pages, such as the setup, have no entry at all.
 */
final class AdminPageRegistrar implements Registrar
{
    public const string MENU_SLUG = 'orillacart';
    public const string ELEMENT_PREFIX = 'orillacart-admin-';
    /** The class of a submenu entry that is not shown: a tab's page, or a hidden one. */
    public const string HIDDEN_CLASS = 'orillacart-menu-hidden';

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
        add_action('admin_head', static function (): void {
            printf('<style>#adminmenu .%s{display:none}</style>', self::HIDDEN_CLASS);
        });
        add_filter('submenu_file', $this->submenuFile(...));
    }

    /** @return list<AdminPage> in menu order: by group, then within it */
    public function pages(): array
    {
        $pages = [...$this->pages];
        usort($pages, static fn(AdminPage $a, AdminPage $b): int => [$a->group()->order(), $a->menuOrder(), $a->slug()] <=> [$b->group()->order(), $b->menuOrder(), $b->slug()]);

        return $pages;
    }

    /**
     * The menu's groups in order, each with its pages in order; the hidden group too, last, when it has pages.
     *
     * @return list<array{group: MenuGroup, pages: non-empty-list<AdminPage>}>
     */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->pages() as $page) {
            $groups[$page->group()->value][] = $page;
        }
        $list = [];
        foreach ($groups as $pages) {
            $list[] = ['group' => $pages[0]->group(), 'pages' => $pages];
        }

        return $list;
    }

    /**
     * The page's slug in the URL: admin.php?page=<slug>. The first page of the first shown group is the top-level
     * entry itself.
     */
    public function pageSlug(AdminPage $page): string
    {
        return $page === $this->first() ? self::MENU_SLUG : self::MENU_SLUG . '-' . $page->slug();
    }

    private function first(): ?AdminPage
    {
        foreach ($this->groups() as ['group' => $group, 'pages' => $pages]) {
            if ($group !== MenuGroup::Hidden) {
                return $pages[0];
            }
        }

        return null;
    }

    private function addMenu(): void
    {
        $first = $this->first();
        if ($first === null) {
            return;
        }

        // The top-level entry opens the first page; WordPress repeats it as the first submenu item, under the
        // group's name.
        add_menu_page(__('Shop', 'orillacart'), __('Shop', 'orillacart'), $first->capability(), self::MENU_SLUG, '', 'dashicons-cart', 56);

        foreach ($this->groups() as ['group' => $group, 'pages' => $pages]) {
            foreach ($pages as $index => $page) {
                $render = static function () use ($page): void {
                    printf('<div class="wrap" id="%s"></div>', esc_attr(self::ELEMENT_PREFIX . $page->slug()));
                };
                // The group's entry is its first page; the rest are reached by the tabs, and hidden ones by links.
                // They stay in the Shop submenu, unseen: WordPress opens the menu of the page it finds there.
                $shown = $index === 0 && $group !== MenuGroup::Hidden;
                $slug = $this->pageSlug($page);
                $hook = add_submenu_page(self::MENU_SLUG, $page->title(), $shown ? $group->label() : $page->title(), $page->capability(), $slug, $render);
                if (!$shown) {
                    $this->hide($slug);
                }
                if (\is_string($hook)) {
                    $this->screens[$hook] = $page;
                }
            }
        }
    }

    private function hide(string $slug): void
    {
        $submenu = &$GLOBALS['submenu'];
        if (!\is_array($submenu) || !\is_array($submenu[self::MENU_SLUG] ?? null)) {
            return;
        }
        foreach ($submenu[self::MENU_SLUG] as &$item) {
            if (\is_array($item) && ($item[2] ?? null) === $slug) {
                $item[4] = self::HIDDEN_CLASS;
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
        wp_add_inline_script('orillacart-' . $page->script(), 'window.orillacartAdmin = ' . wp_json_encode($this->navigation($page)) . ';', 'before');
    }

    /**
     * What the screen's frame shows (shared/ui/Page.tsx): the page, its group, and the group's tabs when it has
     * more than one page.
     *
     * @return array{page: string, group: string, tabs: list<array{id: string, label: string, href: string}>}
     */
    public function navigation(AdminPage $current): array
    {
        $tabs = [];
        foreach ($this->groups() as ['group' => $group, 'pages' => $pages]) {
            if ($group !== $current->group() || $group === MenuGroup::Hidden || \count($pages) < 2) {
                continue;
            }
            foreach ($pages as $page) {
                if (!current_user_can($page->capability())) {
                    continue;
                }
                $tabs[] = ['id' => $page->slug(), 'label' => $page->title(), 'href' => admin_url('admin.php?page=' . $this->pageSlug($page))];
            }
        }

        return ['page' => $current->slug(), 'group' => $current->group()->label(), 'tabs' => \count($tabs) > 1 ? $tabs : [], 'hosts' => self::hosts()];
    }

    /**
     * The site's own hosts, which a screen may link to with a full address: the site's and the admin's, and those
     * WordPress lets redirects go to (allowed_redirect_hosts), as wp_validate_redirect() takes them.
     *
     * @return list<string>
     */
    private static function hosts(): array
    {
        $own = array_values(array_filter([wp_parse_url(home_url(), \PHP_URL_HOST), wp_parse_url(admin_url(), \PHP_URL_HOST)], \is_string(...)));
        $allowed = apply_filters('allowed_redirect_hosts', $own, '');

        return array_values(array_unique(array_map(strtolower(...), array_filter(\is_array($allowed) ? $allowed : $own, \is_string(...)))));
    }

    /** A page reached by a tab highlights its group's entry. */
    private function submenuFile(?string $submenuFile): ?string
    {
        $current = $this->current();
        if ($current === null) {
            return $submenuFile;
        }
        foreach ($this->groups() as ['group' => $group, 'pages' => $pages]) {
            if ($group === $current->group()) {
                return $this->pageSlug($pages[0]);
            }
        }

        return $submenuFile;
    }

    private function current(): ?AdminPage
    {
        $screen = \function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null) {
            return null;
        }

        return $this->screens[$screen->id] ?? null;
    }
}
