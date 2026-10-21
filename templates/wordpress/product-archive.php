<?php
/**
 * The products of a category, tag or brand, for classic themes without a template of their own: the theme's
 * header and footer around the shop's Products block, after the categories below a category. A theme replaces it with taxonomy-orillacart_category.php
 * (or _tag, _brand).
 */

defined('ABSPATH') || exit;

get_header();
?>
<main id="primary" class="site-main orillacart-archive">
    <header class="page-header">
        <h1 class="page-title"><?php single_term_title(); ?></h1>
        <?php the_archive_description('<div class="archive-description">', '</div>'); ?>
    </header>
    <?php
    // A category's page leads to the categories below it first; the blocks escape their own output.
    $below = is_tax('orillacart_category') ? '<!-- wp:orillacart/product-categories /-->' : '';
    echo do_blocks($below . '<!-- wp:orillacart/products /-->'); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>
</main>
<?php
get_footer();
