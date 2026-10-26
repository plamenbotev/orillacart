# 45 · Templates per product and category

Status: built (2026-10-23), PLAN item 105.

## What a shop can do

- **A product chooses its template** in the editor's Template panel (block themes): the plugin's "Product" (image
  beside the name, description and buying form), "Product: wide image" (the image across the page above the
  rest), or "Product: text only" (no image, for a service or a download), and any template the theme or the site
  editor adds for products. Each is made of the product blocks (design 50, from 2026-10-27; before, the buying form came with the product's
  content). The page's main part carries `orillacart-product-page` and a class for the variant
  (`orillacart-product-page--wide`, `--text`) for the theme's styles.
- **A template for one category, tag or brand** made in the site editor (Add template → Product category → that
  category: `taxonomy-orillacart_category-{slug}`) wins over the plugin's template for all of them, as WordPress's
  template hierarchy has it; the end-to-end tests check it. The same for one product
  (`single-orillacart_product-{slug}`), except that a template the product chose in the Template panel wins over
  both, as WordPress has it for every post type.
- **Classic themes** offer the theme's own PHP templates that name the product post type
  (`Template Post Type: orillacart_product`) in the same panel, as WordPress does for any post type, and their
  `taxonomy-orillacart_category-{slug}.php` wins over the plugin's archive template (built in 94).

## Not built

A "Template" choice on the category screen that its subcategories and products take: left until shops ask for
it, as decided when the item was deferred (2026-10-22). One template per category in the site editor covers a
category; a subcategory needs its own.
