<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The shop's pages in each of the site's languages (design 51), with Polylang or WPML. A page made before either
 * has no language, so it has no translations and every link goes to it: it is given the default language. Each
 * language without a version of a page gets one, linked to it: the shop's own pages (the ones it made, holding
 * only the shop's blocks), published under their title in that language; a page with the owner's words (a heading,
 * text of their own, the legal pages) as a draft of the same words to translate, which links pass by until it is
 * published. A translation the owner already has is left as it is, and a page and language once seen together
 * are not made again: a version the owner deleted for good stays deleted.
 *
 * Done when the plugin is activated or updated, and in the admin when the languages or the chosen pages changed.
 */
#[AsHook('admin_init', method: 'changed')]
final readonly class ShopPageLanguages implements LifecycleListener
{
    /** What the pages and languages were when last done, and the version of each page in each language seen. */
    public const string DONE_OPTION = 'orillacart_page_languages';

    /** The shop's own parts; uninstalling removes the versions made of them, as it does the pages. */
    private const array SHOP_PARTS = ['shop' => true, 'cart' => true, 'checkout' => true, 'account' => true];

    public function __construct(
        private OptionShopPagesRepository $pages,
        private OptionLegalSettingsRepository $legal,
        private ContentLanguages $languages,
        private Languages $locales,
    ) {}

    public function changed(): void
    {
        if ($this->languages->multilingual() && $this->done()['state'] !== $this->state()) {
            $this->keep();
        }
    }

    public function keep(): void
    {
        $default = $this->languages->default();
        if (!$this->languages->multilingual() || $default === null) {
            return;
        }
        $done = $this->done();
        foreach ($this->pagesByPart() as $part => $id) {
            if (get_post_type($id) !== 'page' || \in_array(get_post_status($id), [false, 'trash'], true)) {
                continue;
            }
            if ($this->languages->ofPost($id) === null) {
                $this->languages->setPostLanguage($id, $default);
            }
            $translations = $this->languages->postTranslations($id);
            foreach ($this->languages->languages() as $language) {
                $seen = "{$id}:{$language->code}";
                if (isset($translations[$language->code])) {
                    $done['versions'][$seen] = $translations[$language->code];
                } elseif (!isset($done['versions'][$seen])) {
                    $made = $this->translate($id, $part, $language);
                    if ($made !== null) {
                        $done['versions'][$seen] = $made;
                    }
                }
            }
        }
        update_option(self::DONE_OPTION, ['state' => $this->state(), 'versions' => $done['versions']], false);
    }

    public function activate(): void
    {
        $this->keep();
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->keep();
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::DONE_OPTION);
    }

    /** @return int|null the version made, null when WordPress refused it */
    private function translate(int $id, string $part, Language $language): ?int
    {
        $page = get_post($id);
        if (!$page instanceof \WP_Post) {
            return null;
        }
        $title = self::shopTitle($part);
        // The shop's own page: one it made, holding nothing but the shop's blocks. Its title in the language is
        // the shop's; whatever the owner titled it, in whichever language the admin worked in.
        $own = $title !== null && $this->pages->wasMade($id) && self::onlyShopBlocks($page->post_content);
        $translation = wp_insert_post(wp_slash([
            'post_type' => 'page',
            'post_status' => $own ? $page->post_status : 'draft',
            'post_title' => $own ? $this->locales->in($language->locale, $title) : $page->post_title,
            'post_content' => $page->post_content,
            'post_parent' => $page->post_parent,
            'menu_order' => $page->menu_order,
            'comment_status' => $page->comment_status,
        ]), true);
        if ($translation instanceof \WP_Error) {
            return null;
        }
        $this->languages->addPostTranslation($id, $translation, $language);
        if (\array_key_exists($part, self::SHOP_PARTS)) {
            $this->pages->made($translation);
        }

        return $translation;
    }

    /**
     * The shop's title of the part, in the language it is called in; null for a page the owner wrote.
     *
     * @return (\Closure(): string)|null
     */
    private static function shopTitle(string $part): ?\Closure
    {
        return match ($part) {
            'shop' => static fn(): string => __('Shop', 'orillacart'),
            'cart' => static fn(): string => __('Cart', 'orillacart'),
            'checkout' => static fn(): string => __('Checkout', 'orillacart'),
            'account' => static fn(): string => __('My account', 'orillacart'),
            default => null,
        };
    }

    private static function onlyShopBlocks(string $content): bool
    {
        $blocks = array_filter(parse_blocks($content), static fn(array $block): bool => $block['blockName'] !== null);

        return $blocks !== [] && array_all($blocks, static fn(array $block): bool => str_starts_with((string) $block['blockName'], 'orillacart/') && $block['innerBlocks'] === []);
    }

    /**
     * The shop's pages and the legal ones, by part.
     *
     * @return array<string, int>
     */
    private function pagesByPart(): array
    {
        $shop = $this->pages->load();
        $legal = $this->legal->load();

        return array_filter([
            'shop' => $shop->shop,
            'cart' => $shop->cart,
            'checkout' => $shop->checkout,
            'account' => $shop->account,
            'terms' => $legal->terms,
            'withdrawal' => $legal->withdrawal,
            'delivery' => $legal->delivery,
            'legal_notice' => $legal->legalNotice,
            'withdrawal_form' => $legal->withdrawalForm,
        ], static fn(?int $id): bool => $id !== null);
    }

    private function state(): string
    {
        return md5((string) json_encode([array_map(static fn(Language $language): string => $language->code, $this->languages->languages()), $this->pagesByPart()]));
    }

    /**
     * @return array{state: ?string, versions: array<string, int>} the versions by "page id:language code"
     */
    private function done(): array
    {
        $stored = get_option(self::DONE_OPTION);
        $versions = \is_array($stored) && \is_array($stored['versions'] ?? null) ? $stored['versions'] : [];

        return [
            'state' => \is_array($stored) && \is_string($stored['state'] ?? null) ? $stored['state'] : null,
            'versions' => array_filter($versions, static fn(mixed $id, mixed $key): bool => \is_string($key) && \is_int($id), \ARRAY_FILTER_USE_BOTH),
        ];
    }
}
