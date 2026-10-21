<?php
/**
 * The products of a category, tag or brand, for classic themes without a template of their own: the theme's
 * header and footer around the shop's Products block. A theme replaces it with taxonomy-orillacart_category.php
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
    <?php echo do_blocks('<!-- wp:orillacart/products /-->'); // phpcs:ignore WordPress.Security.EscapeOutput -- the block escapes its own output ?>
</main>
<?php
get_footer();
