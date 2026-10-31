<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryThumbnail;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * The shop's brands as the storefront lists them, by name, in the language shown: on a site in several
 * languages each brand is its translation there, with the logo of the brand it translates when it has none.
 *
 * @phpstan-type Brand array{id: int, name: string, url: string, image_id: int, count: int}
 */
final readonly class ProductBrands
{
    public function __construct(private ContentLanguages $languages) {}

    /**
     * Every brand, those without published products left out unless $empty.
     *
     * @return list<Brand>
     */
    public function all(bool $empty = false): array
    {
        return array_values(array_filter($this->brands(), static fn(array $brand): bool => $empty || $brand['count'] > 0));
    }

    /**
     * The brands chosen, in the order given, each in the language shown; those gone, or without products unless
     * $empty, left out.
     *
     * @param list<int> $ids
     *
     * @return list<Brand>
     */
    public function chosen(array $ids, bool $empty = false): array
    {
        $byId = [];
        foreach ($this->brands() as $brand) {
            $byId[$brand['id']] = $brand;
        }
        $code = $this->languages->multilingual() ? $this->languages->current()?->code : null;
        $chosen = [];
        foreach ($ids as $id) {
            // The editor chose the brand in its own language: shown as its translation in this one.
            $id = $code === null ? $id : ($this->languages->termTranslations($id, ProductBrandTaxonomy::NAME)[$code] ?? $id);
            $brand = $byId[$id] ?? null;
            if ($brand !== null && ($empty || $brand['count'] > 0) && !\in_array($brand, $chosen, true)) {
                $chosen[] = $brand;
            }
        }

        return $chosen;
    }

    /**
     * @return list<Brand> by name
     */
    private function brands(): array
    {
        $terms = get_terms(['taxonomy' => ProductBrandTaxonomy::NAME, 'hide_empty' => false, 'orderby' => 'name']);
        if (!\is_array($terms)) {
            return [];
        }
        $multilingual = $this->languages->multilingual();
        $brands = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $url = get_term_link($term);
            $image = CategoryThumbnail::of($term->term_id);
            if ($image === 0 && $multilingual) {
                foreach ($this->languages->termTranslations($term->term_id, ProductBrandTaxonomy::NAME) as $translation) {
                    $image = $image > 0 ? $image : CategoryThumbnail::of($translation);
                }
            }
            $brands[] = [
                'id' => $term->term_id,
                'name' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => \is_string($url) ? $url : '',
                'image_id' => $image,
                'count' => $term->count,
            ];
        }
        usort($brands, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $brands;
    }
}
