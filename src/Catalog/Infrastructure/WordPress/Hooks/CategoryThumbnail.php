<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A picture for each product category, and a logo for each brand, from the media library: on their screens, in
 * their lists, beside the categories on the shop page and in the Product brands block. Kept in term meta, which
 * the REST API also offers.
 */
#[AsHook('init', method: 'registerMeta')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_add_form_fields', method: 'addField')]
#[AsHook(ProductCategoryTaxonomy::NAME . '_edit_form_fields', method: 'editField', arguments: 2)]
#[AsHook('created_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('edited_' . ProductCategoryTaxonomy::NAME, method: 'save')]
#[AsHook('manage_edit-' . ProductCategoryTaxonomy::NAME . '_columns', method: 'columns', filter: true)]
#[AsHook('manage_' . ProductCategoryTaxonomy::NAME . '_custom_column', method: 'column', filter: true, arguments: 3)]
#[AsHook(ProductBrandTaxonomy::NAME . '_add_form_fields', method: 'addField')]
#[AsHook(ProductBrandTaxonomy::NAME . '_edit_form_fields', method: 'editField', arguments: 2)]
#[AsHook('created_' . ProductBrandTaxonomy::NAME, method: 'save')]
#[AsHook('edited_' . ProductBrandTaxonomy::NAME, method: 'save')]
#[AsHook('manage_edit-' . ProductBrandTaxonomy::NAME . '_columns', method: 'columns', filter: true)]
#[AsHook('manage_' . ProductBrandTaxonomy::NAME . '_custom_column', method: 'column', filter: true, arguments: 3)]
#[AsHook('admin_enqueue_scripts', method: 'scripts')]
final readonly class CategoryThumbnail
{
    public const string META = 'orillacart_thumbnail';
    private const string FIELD = 'orillacart_thumbnail';
    private const string COLUMN = 'orillacart_picture';
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME];

    /** The category's picture, as a media library id; 0 for none. */
    public static function of(int $termId): int
    {
        $value = get_term_meta($termId, self::META, true);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function registerMeta(): void
    {
        foreach (self::TAXONOMIES as $taxonomy) {
            register_term_meta($taxonomy, self::META, [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'show_in_rest' => true,
                'sanitize_callback' => static fn(mixed $value): int => is_numeric($value) ? max(0, (int) $value) : 0,
            ]);
        }
    }

    public function addField(string $taxonomy = ProductCategoryTaxonomy::NAME): void
    {
        echo '<div class="form-field">' . $this->field(0, $taxonomy) . '</div>';
    }

    public function editField(\WP_Term $term, string $taxonomy = ProductCategoryTaxonomy::NAME): void
    {
        printf('<tr class="form-field"><th scope="row">%s</th><td>%s</td></tr>', esc_html(self::label($taxonomy)), $this->field(self::of($term->term_id), $taxonomy, false));
    }

    /**
     * Saves the picture from the category screens, whose forms WordPress checked before making or changing the
     * term. Terms made elsewhere carry no field and keep their picture.
     */
    public function save(int $termId): void
    {
        // WordPress checked the nonce of the category forms before calling this.
        $value = $_POST[self::FIELD] ?? null;
        if (!is_numeric($value) || !current_user_can('edit_term', $termId)) {
            return;
        }
        $id = (int) $value;
        if ($id > 0 && !wp_attachment_is_image($id)) {
            return;
        }
        update_term_meta($termId, self::META, $id);
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $cb = \array_slice($columns, 0, 1, true);

        return $cb + [self::COLUMN => '<span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Picture', 'orillacart') . '</span>'] + $columns;
    }

    public function column(string $content, string $column, int $termId): string
    {
        return $column === self::COLUMN ? (string) wp_get_attachment_image(self::of($termId), [40, 40]) : $content;
    }

    public function scripts(string $hook): void
    {
        $screen = get_current_screen();
        if (!\in_array($hook, ['edit-tags.php', 'term.php'], true) || !\in_array($screen?->taxonomy, self::TAXONOMIES, true)) {
            return;
        }
        wp_enqueue_media();
        // Choosing, showing and removing the picture with WordPress's media library.
        wp_add_inline_script('media-editor', <<<'JS'
            document.addEventListener( 'click', ( event ) => {
                const button = event.target.closest( '[data-orillacart-picture]' );
                if ( ! button ) { return; }
                event.preventDefault();
                const box = button.closest( '.orillacart-picture' );
                const input = box.querySelector( 'input' );
                const preview = box.querySelector( 'img' );
                if ( button.dataset.orillacartPicture === 'remove' ) {
                    input.value = '0';
                    preview.hidden = true;
                    return;
                }
                const frame = wp.media( { library: { type: 'image' }, multiple: false } );
                frame.on( 'select', () => {
                    const chosen = frame.state().get( 'selection' ).first().toJSON();
                    input.value = String( chosen.id );
                    preview.src = ( chosen.sizes && chosen.sizes.thumbnail ? chosen.sizes.thumbnail : chosen ).url;
                    preview.hidden = false;
                } );
                frame.open();
            } );
            // After a category is added the form stays for the next one: clear its picture.
            if ( window.jQuery ) {
                window.jQuery( document ).ajaxComplete( ( event, request, options ) => {
                    if ( options && typeof options.data === 'string' && options.data.indexOf( 'action=add-tag' ) !== -1 ) {
                        document.querySelectorAll( '#addtag .orillacart-picture input' ).forEach( ( input ) => { input.value = '0'; } );
                        document.querySelectorAll( '#addtag .orillacart-picture img' ).forEach( ( image ) => { image.hidden = true; } );
                    }
                } );
            }
            JS);
    }

    private function field(int $attachment, string $taxonomy, bool $withLabel = true): string
    {
        $src = $attachment > 0 ? wp_get_attachment_image_url($attachment, 'thumbnail') : false;
        $brand = $taxonomy === ProductBrandTaxonomy::NAME;

        return \sprintf(
            '%s<div class="orillacart-picture"><img src="%s" alt="" width="60" height="60" style="object-fit:%s;display:block;margin-bottom:6px"%s><input type="hidden" name="%s" value="%d"><button type="button" class="button" data-orillacart-picture="choose">%s</button> <button type="button" class="button-link" data-orillacart-picture="remove">%s</button><p class="description">%s</p></div>',
            $withLabel ? '<label>' . esc_html(self::label($taxonomy)) . '</label>' : '',
            esc_url(\is_string($src) ? $src : ''),
            // A logo is shown whole.
            $brand ? 'contain' : 'cover',
            \is_string($src) ? '' : ' hidden',
            esc_attr(self::FIELD),
            $attachment,
            $brand ? esc_html__('Choose a logo', 'orillacart') : esc_html__('Choose a picture', 'orillacart'),
            esc_html__('Remove', 'orillacart'),
            $brand ? esc_html__('Shown with the brand’s name in the Product brands block.', 'orillacart') : esc_html__('Shown beside the category on the shop page.', 'orillacart'),
        );
    }

    private static function label(string $taxonomy): string
    {
        return $taxonomy === ProductBrandTaxonomy::NAME ? __('Logo', 'orillacart') : __('Picture', 'orillacart');
    }
}
