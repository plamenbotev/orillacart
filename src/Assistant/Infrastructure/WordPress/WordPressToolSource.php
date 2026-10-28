<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\InputSchemas;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryLayout;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryOrder;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\CategoryThumbnail;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Exchange\Application\ProductPosts;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Application\LegalSettingsService;
use OrillaCart\Storefront\Application\ShopPagesService;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ShopPatterns;
use Psr\Log\LoggerInterface;

/**
 * Tools for what WordPress keeps of the shop rather than the shop's own tables: a product's post (name, texts,
 * categories, brands, tags, pictures), the categories, the media library's images and the shop's own pages (the
 * shop, cart, checkout and account pages, the legal ones, and the landing pages the assistant made from the shop's
 * patterns), never the site's other pages. The commerce side of a product (price, stock, shipping) is the route tools' (show_product, update_product).
 * A change is written into the change log as the assistant's, as a route's would be.
 */
final readonly class WordPressToolSource implements ToolSource
{
    public const string AREA = 'WordPress';

    private const array STATUSES = ['publish', 'draft', 'pending', 'private'];
    /** Marks a page the assistant made as a landing page, which its page tools may then change. */
    public const string LANDING = '_orillacart_landing';

    public function __construct(
        private ProductPosts $posts,
        private ToolFiles $files,
        private ChangeLog $log,
        private LoggerInterface $logger,
        private ShopPagesService $shopPages,
        private LegalSettingsService $legal,
        private ProductTranslations $translations,
        private ContentLanguages $languages,
    ) {}

    public function tools(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $texts = [
            'name' => ['description' => 'The product\'s name.', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'status' => ['description' => 'publish puts it on sale; draft keeps it hidden.', 'enum' => self::STATUSES],
            'description' => ['description' => 'The long description, HTML allowed.', 'type' => 'string', 'maxLength' => 65535],
            'short_description' => ['description' => 'The short description shown beside the price.', 'type' => 'string', 'maxLength' => 5000],
            'categories' => ['description' => 'Its categories, each as its path ("Lighting > Desk lamps"); a missing one is made. Replaces the categories it had.', 'type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 400], 'maxItems' => 50],
            'brands' => ['description' => 'Its brands by name; replaces the ones it had.', 'type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'maxItems' => 20],
            'tags' => ['description' => 'Its tags by name; replaces the ones it had.', 'type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'maxItems' => 50],
            'image_ids' => ['description' => 'Its pictures from the media library (upload_image gives an id): the first is the main picture, the rest the gallery. Replaces the ones it had.', 'type' => 'array', 'items' => $id, 'maxItems' => 50],
        ];

        return [
            self::tool('find_products', 'Find products', 'Products by words in their name or text, status or category, a page at a time, with their categories and main picture; nothing changes. show_product gives one\'s price, stock and variations.', [
                'search' => ['description' => 'Words in the name or text.', 'type' => 'string', 'maxLength' => 200],
                'status' => ['description' => 'Only products in this state; all but the trash when left out.', 'enum' => [...self::STATUSES, 'trash']],
                'category' => ['description' => 'Only products in this category, by its id.', 'type' => 'integer', 'minimum' => 1],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ], [], true),
            self::tool('create_product', 'Create product', 'Makes a product: its name, texts, categories, brands, tags and pictures, as a draft unless told to publish. Then set its price, stock and shipping with update_product.', $texts, ['name'], false),
            self::tool('change_product_content', 'Change product content', 'Changes a product\'s name, state, texts, categories, brands, tags or pictures; what is left out stays. Price, stock and shipping are update_product\'s.', ['id' => $id] + $texts, ['id'], false),
            self::tool('trash_product', 'Trash product', 'Moves a product to the trash: it is no longer sold or shown; placed orders keep it. It can be restored with change_product_content, status draft.', ['id' => $id], ['id'], false, Tool::DELETING),
            self::tool('list_categories', 'List categories', 'The product categories, each with its parent, order, layout, picture and how many products it has; nothing changes.', [], [], true),
            self::tool('save_category', 'Save category', 'Adds a product category, or changes one when id is given: its name, parent, description, place in the order, layout and picture.', [
                'id' => $id + ['description' => 'The category to change; left out, one is added.'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'parent' => ['description' => 'The parent category\'s id; 0 for none.', 'type' => 'integer', 'minimum' => 0],
                'description' => ['type' => 'string', 'maxLength' => 5000],
                'order' => ['description' => 'Its place among its siblings: lower comes first.', 'type' => 'integer'],
                'layout' => ['description' => 'How its products are shown; empty for the shop\'s default.', 'enum' => ['', ...CategoryLayout::LAYOUTS]],
                'thumbnail_id' => ['description' => 'Its picture from the media library; 0 for none.', 'type' => 'integer', 'minimum' => 0],
            ], ['name'], false),
            self::tool('delete_category', 'Delete category', 'Deletes a product category; its products stay, in their other categories.', ['id' => $id], ['id'], false, Tool::DELETING),
            self::tool('upload_image', 'Upload image', 'Puts a picture into the media library, from base64 or an address the shop fetches, and answers with its id for create_product, change_product_content and save_category.', InputSchemas::FILE + [
                'alt' => ['description' => 'What the picture shows, for people who cannot see it.', 'type' => 'string', 'maxLength' => 500],
            ], ['file_name'], false),
            self::tool('list_pages', 'List the shop\'s pages', 'The shop\'s own pages, each with what it is for: the shop, cart, checkout and account pages, the legal pages the shop links to (terms, right of withdrawal, the withdrawal form, shipping and payment, legal notice), and the landing pages made with create_landing_page. The site\'s other pages are not the shop\'s; nothing changes.', [], [], true),
            self::tool('list_patterns', 'List the shop\'s patterns', 'The shop\'s block patterns for landing pages (hero, categories, sale, new arrivals, product spotlight, gift card, a whole launch page), each with its blocks, to make a page from with create_landing_page or to put into a shop page; nothing changes.', [], [], true),
            self::tool('create_landing_page', 'Create landing page', 'Makes a landing page as a draft, from the shop\'s patterns (by name, in order) and blocks or HTML after them. It is then one of the shop\'s pages: change it with change_page, put it live with publish_page. A product spotlight shows nothing until its Single product block names a product (productId).', [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'patterns' => ['description' => 'Pattern names from list_patterns, such as orillacart/shop-hero.', 'type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 100], 'maxItems' => 20],
                'content' => ['description' => 'Blocks or HTML after the patterns.', 'type' => 'string', 'maxLength' => 200000],
            ], ['title'], false),
            self::tool('show_page', 'Show page', 'One of the shop\'s pages with its whole text; nothing changes.', ['id' => $id], ['id'], true),
            self::tool('change_page', 'Change page', 'Changes the title or text of one of the shop\'s pages that is not published, such as a legal draft the setup made. A published page changes, or is taken down, with publish_page.', [
                'id' => $id,
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'content' => ['description' => 'The whole text, HTML or blocks; replaces what it had.', 'type' => 'string', 'maxLength' => 200000],
            ], ['id'], false),
            self::tool('publish_page', 'Publish page', 'Puts one of the shop\'s pages live for customers, with a new title or text if given, or takes it back to a draft (publish: false). Changing a published page\'s text is this too. Show the owner the text first: legal pages are what customers agree to.', [
                'id' => $id,
                'publish' => ['description' => 'true puts it live; false takes it back to a draft.', 'type' => 'boolean', 'default' => true],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'content' => ['description' => 'The whole text, HTML or blocks; replaces what it had.', 'type' => 'string', 'maxLength' => 200000],
            ], ['id'], false, Tool::PUBLISHING),
        ];
    }

    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
    {
        try {
            $answer = match ($tool->name) {
                'find_products' => $this->findProducts($arguments),
                'create_product' => $this->createProduct($arguments),
                'change_product_content' => $this->changeProduct($arguments),
                'trash_product' => $this->trashProduct($arguments),
                'list_categories' => $this->categories(),
                'save_category' => $this->saveCategory($arguments),
                'delete_category' => $this->deleteCategory($arguments),
                'upload_image' => $this->uploadImage($arguments),
                'list_pages' => $this->pages(),
                'list_patterns' => self::patterns(),
                'create_landing_page' => $this->createLandingPage($arguments),
                'show_page' => $this->page(self::id($arguments), true),
                'change_page' => $this->changePage($arguments, null),
                'publish_page' => $this->changePage($arguments, ($arguments['publish'] ?? true) === false ? 'draft' : 'publish'),
                default => throw new \InvalidArgumentException("The shop has no tool {$tool->name}."),
            };
        } catch (\InvalidArgumentException $e) {
            return ToolOutcome::refused($e->getMessage());
        }
        if (!$tool->readOnly) {
            unset($arguments['file_base64']);
            try {
                $this->log->record($caller->userId, 'CALL', '/assistant/' . $tool->name, '/assistant/' . $tool->name, $arguments, RouteDefinition::VIA_ASSISTANT, $caller->keyName);
            } catch (\Throwable $e) {
                $this->logger->error('The change log could not record the tool {tool}: {message}', ['tool' => $tool->name, 'message' => $e->getMessage()]);
            }
        }

        return ToolOutcome::done($answer);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     */
    private static function tool(string $name, string $title, string $description, array $properties, array $required, bool $readOnly, ?string $risk = null): ToolDefinition
    {
        $schema = $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;

        return new ToolDefinition($name, $title, $description, $schema, $readOnly, !$readOnly && $risk !== null, $readOnly, $risk, self::AREA, Capability::MANAGE_SHOP);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function findProducts(array $arguments): array
    {
        $status = self::optionalString($arguments, 'status');
        $query = [
            'post_type' => ProductPostType::NAME,
            'post_status' => $status ?? ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => min(100, max(1, self::optionalInt($arguments, 'per_page') ?? 20)),
            'paged' => max(1, self::optionalInt($arguments, 'page') ?? 1),
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        $search = self::optionalString($arguments, 'search');
        if ($search !== null && $search !== '') {
            $query['s'] = $search;
        }
        $category = self::optionalInt($arguments, 'category');
        if ($category !== null) {
            $query['tax_query'] = [['taxonomy' => ProductCategoryTaxonomy::NAME, 'field' => 'term_id', 'terms' => [$category]]];
        }
        $found = new \WP_Query($query);
        $products = [];
        foreach (\is_array($found->posts) ? $found->posts : [] as $post) {
            if ($post instanceof \WP_Post) {
                $products[] = $this->product($post);
            }
        }

        return ['products' => $products, 'total' => $found->found_posts, 'pages' => $found->max_num_pages];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function createProduct(array $arguments): array
    {
        $name = self::requiredString($arguments, 'name', 200);
        $status = self::optionalStatus($arguments) ?? 'draft';
        $images = self::images($arguments);
        $id = $this->posts->create($name, $status);
        $this->posts->change($id, self::change($arguments));
        if ($images !== null) {
            self::setImages($id, $images);
        }

        return $this->productOf($id) + ['next' => 'Set its price, stock and shipping with update_product.'];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function changeProduct(array $arguments): array
    {
        $id = self::productId($arguments);
        $images = self::images($arguments);
        if (isset($arguments['name'])) {
            self::requiredString($arguments, 'name', 200);
        }
        self::optionalStatus($arguments);
        $this->posts->change($id, self::change($arguments));
        if ($images !== null) {
            self::setImages($id, $images);
        }

        return $this->productOf($id);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function trashProduct(array $arguments): array
    {
        $id = self::productId($arguments);
        if (wp_trash_post($id) === false) {
            throw new \InvalidArgumentException('The product could not be moved to the trash.');
        }

        return $this->productOf($id);
    }

    /** @return array<string, mixed> */
    private function productOf(int $id): array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            throw new \InvalidArgumentException("There is no product {$id}.");
        }

        return $this->product($post);
    }

    /** @return array<string, mixed> */
    private function product(\WP_Post $post): array
    {
        $categories = get_the_terms($post->ID, ProductCategoryTaxonomy::NAME);
        $image = get_post_thumbnail_id($post->ID);
        $gallery = get_post_meta($post->ID, ProductGallery::META, true);

        return [
            'id' => $post->ID,
            'name' => $post->post_title,
            'status' => $post->post_status,
            'categories' => \is_array($categories) ? array_values(array_map(static fn(\WP_Term $term): array => ['id' => $term->term_id, 'name' => $term->name], $categories)) : [],
            'image_ids' => array_values(array_filter([$image === false ? 0 : (int) $image, ...(\is_array($gallery) ? array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $gallery) : [])], static fn(int $id): bool => $id > 0)),
            'permalink' => (string) get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
            // A translation is the product's text in another language; its price and stock are the original's.
            'language' => $this->languages->ofPost($post->ID),
            'translation_of' => $this->translations->isTranslation($post->ID) ? $this->translations->original($post->ID) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        $terms = get_terms(['taxonomy' => ProductCategoryTaxonomy::NAME, 'hide_empty' => false]);
        if (!\is_array($terms)) {
            return [];
        }
        $categories = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $categories[] = self::category($term);
            }
        }

        return $categories;
    }

    /** @return array<string, mixed> */
    private static function category(\WP_Term $term): array
    {
        return [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'parent' => $term->parent,
            'description' => $term->description,
            'products' => $term->count,
            'order' => self::number(get_term_meta($term->term_id, CategoryOrder::META, true)),
            'layout' => \is_string($layout = get_term_meta($term->term_id, CategoryLayout::META, true)) ? $layout : '',
            'thumbnail_id' => self::number(get_term_meta($term->term_id, CategoryThumbnail::META, true)),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function saveCategory(array $arguments): array
    {
        $name = self::requiredString($arguments, 'name', 200);
        // Only what was given: a change leaves the rest as it was.
        $fields = [];
        if (\array_key_exists('description', $arguments)) {
            $fields['description'] = self::optionalString($arguments, 'description') ?? '';
        }
        if (\array_key_exists('parent', $arguments)) {
            $fields['parent'] = max(0, self::optionalInt($arguments, 'parent') ?? 0);
        }
        $id = self::optionalInt($arguments, 'id');
        if ($id === null) {
            $saved = wp_insert_term($name, ProductCategoryTaxonomy::NAME, $fields);
        } else {
            if (!get_term($id, ProductCategoryTaxonomy::NAME) instanceof \WP_Term) {
                throw new \InvalidArgumentException("There is no category {$id}.");
            }
            $saved = wp_update_term($id, ProductCategoryTaxonomy::NAME, ['name' => $name] + $fields);
        }
        if ($saved instanceof \WP_Error) {
            throw new \InvalidArgumentException($saved->get_error_message());
        }
        $termId = (int) $saved['term_id'];
        foreach (['order' => CategoryOrder::META, 'layout' => CategoryLayout::META, 'thumbnail_id' => CategoryThumbnail::META] as $argument => $meta) {
            if (\array_key_exists($argument, $arguments)) {
                update_term_meta($termId, $meta, $arguments[$argument]);
            }
        }
        $term = get_term($termId, ProductCategoryTaxonomy::NAME);
        if (!$term instanceof \WP_Term) {
            throw new \InvalidArgumentException('The category was not saved.');
        }

        return self::category($term);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteCategory(array $arguments): array
    {
        $id = self::id($arguments);
        $deleted = wp_delete_term($id, ProductCategoryTaxonomy::NAME);
        if ($deleted !== true) {
            throw new \InvalidArgumentException($deleted instanceof \WP_Error ? $deleted->get_error_message() : "There is no category {$id}, or it is the default one.");
        }

        return ['deleted' => $id];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function uploadImage(array $arguments): array
    {
        $name = sanitize_file_name(self::requiredString($arguments, 'file_name', 200));
        $temporary = $this->files->write($arguments);
        try {
            $type = wp_check_filetype_and_ext($temporary, $name);
            if (!\is_string($type['type'] ?? null) || !str_starts_with($type['type'], 'image/')) {
                throw new \InvalidArgumentException('That is not a picture WordPress takes (JPEG, PNG, GIF, WebP, AVIF): check the file and its name\'s extension.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $file = ['name' => $name, 'tmp_name' => $temporary];
            $id = media_handle_sideload($file, 0);
            if ($id instanceof \WP_Error) {
                throw new \InvalidArgumentException($id->get_error_message());
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $alt = self::optionalString($arguments, 'alt');
        if ($alt !== null) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }

        return ['id' => $id, 'url' => (string) wp_get_attachment_url($id)];
    }

    /**
     * The shop's pages and what each is for, as the shop's settings name them.
     *
     * @return array<int, string> by page id
     */
    private function shopPages(): array
    {
        $shop = $this->shopPages->pages();
        $legal = $this->legal->current();
        $roles = [
            'shop' => $shop->shop, 'cart' => $shop->cart, 'checkout' => $shop->checkout, 'account' => $shop->account,
            'terms' => $legal->terms, 'withdrawal' => $legal->withdrawal, 'withdrawal_form' => $legal->withdrawalForm,
            'delivery' => $legal->delivery, 'legal_notice' => $legal->legalNotice,
        ];
        $pages = [];
        foreach ($roles as $role => $id) {
            if ($id !== null) {
                $pages[$id] ??= $role;
            }
        }
        $landing = get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft', 'pending', 'private'], 'meta_key' => self::LANDING, 'fields' => 'ids', 'numberposts' => 100, 'orderby' => 'ID']); // phpcs:ignore WordPress.DB.SlowDBQuery
        foreach ($landing as $id) {
            if (\is_int($id)) {
                $pages[$id] ??= 'landing';
            }
        }

        return $pages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(): array
    {
        $pages = [];
        foreach (array_keys($this->shopPages()) as $id) {
            try {
                $pages[] = $this->page($id, false);
            } catch (\InvalidArgumentException) {
                // A setting that names a page since deleted.
                continue;
            }
        }

        return $pages;
    }

    /**
     * One of the shop's pages; any other is refused, as the assistant runs the shop, not the site.
     *
     * @return array<string, mixed>
     */
    private function page(int $id, bool $content): array
    {
        $role = $this->shopPages()[$id] ?? throw new \InvalidArgumentException("Page {$id} is not one of the shop's pages; list_pages gives them.");
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'page' || $post->post_status === 'trash') {
            throw new \InvalidArgumentException("There is no page {$id}.");
        }
        $page = ['id' => $post->ID, 'for' => $role, 'title' => $post->post_title, 'status' => $post->post_status, 'link' => (string) get_permalink($post)];

        return $content ? $page + ['content' => $post->post_content] : $page;
    }

    /**
     * The shop's patterns for landing pages (ShopPatterns).
     *
     * @return list<array{name: string, title: string, description: string, content: string}>
     */
    private static function patterns(): array
    {
        $patterns = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            ['name' => $name, 'title' => $title, 'content' => $content] = $pattern + ['name' => null, 'title' => null, 'content' => null];
            if (\is_array($pattern['categories'] ?? null) && \in_array(ShopPatterns::CATEGORY, $pattern['categories'], true) && \is_string($name) && \is_string($title) && \is_string($content)) {
                $patterns[] = [
                    'name' => $name,
                    'title' => $title,
                    'description' => \is_string($pattern['description'] ?? null) ? $pattern['description'] : '',
                    'content' => $content,
                ];
            }
        }

        return $patterns;
    }

    /**
     * A draft page made of the shop's patterns and the blocks given, marked as the shop's landing page.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function createLandingPage(array $arguments): array
    {
        if (!current_user_can('edit_pages')) {
            throw new \InvalidArgumentException('The key\'s shop manager may not make pages.');
        }
        $known = array_column(self::patterns(), 'content', 'name');
        $parts = [];
        foreach (self::strings($arguments, 'patterns') ?? [] as $name) {
            $parts[] = $known[$name] ?? throw new \InvalidArgumentException("The shop has no pattern {$name}; list_patterns gives them.");
        }
        $content = self::optionalString($arguments, 'content');
        if ($content !== null && $content !== '') {
            $parts[] = $content;
        }
        $id = wp_insert_post(wp_slash([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => self::requiredString($arguments, 'title', 200),
            'post_content' => implode("\n\n", $parts),
            'meta_input' => [self::LANDING => '1'],
        ]), true);
        if ($id instanceof \WP_Error) {
            throw new \InvalidArgumentException($id->get_error_message());
        }

        return $this->page($id, true);
    }

    /**
     * A page's title or text, and with publish_page its state: change_page leaves a published page to
     * publish_page, which asks the owner first.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function changePage(array $arguments, ?string $status): array
    {
        $id = self::id($arguments);
        $current = $this->page($id, false);
        if ($status === null && $current['status'] === 'publish') {
            throw new \InvalidArgumentException("Page {$id} is published: customers see it. Change it with publish_page, which asks the owner first.");
        }
        if (!current_user_can('edit_page', $id) || ($status === 'publish' && !current_user_can('publish_pages'))) {
            throw new \InvalidArgumentException('The key\'s shop manager may not edit that page.');
        }
        $post = ['ID' => $id];
        if (isset($arguments['title'])) {
            $post['post_title'] = self::requiredString($arguments, 'title', 200);
        }
        if (\array_key_exists('content', $arguments)) {
            $post['post_content'] = self::optionalString($arguments, 'content') ?? '';
        }
        if ($status !== null) {
            $post['post_status'] = $status;
        }
        $updated = wp_update_post(wp_slash($post), true);
        if ($updated instanceof \WP_Error) {
            throw new \InvalidArgumentException($updated->get_error_message());
        }

        return $this->page($id, true);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function change(array $arguments): ProductPostChange
    {
        return new ProductPostChange(
            isset($arguments['name']) ? self::requiredString($arguments, 'name', 200) : null,
            self::optionalStatus($arguments),
            self::optionalString($arguments, 'description'),
            self::optionalString($arguments, 'short_description'),
            self::strings($arguments, 'categories'),
            self::strings($arguments, 'brands'),
            self::strings($arguments, 'tags'),
        );
    }

    /**
     * @param list<int> $images
     */
    private static function setImages(int $productId, array $images): void
    {
        $main = array_shift($images);
        if ($main === null) {
            delete_post_thumbnail($productId);
        } else {
            set_post_thumbnail($productId, $main);
        }
        update_post_meta($productId, ProductGallery::META, $images);
    }

    /**
     * The pictures given, each one in the media library; null when none were given.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<int>|null
     */
    private static function images(array $arguments): ?array
    {
        if (!\array_key_exists('image_ids', $arguments)) {
            return null;
        }
        if (!\is_array($arguments['image_ids'])) {
            throw new \InvalidArgumentException('image_ids is a list of media ids.');
        }
        $ids = [];
        foreach ($arguments['image_ids'] as $id) {
            if (!\is_int($id) || !wp_attachment_is_image($id)) {
                throw new \InvalidArgumentException('image_ids holds ' . (\is_scalar($id) ? (string) $id : 'a value') . ', which is not a picture in the media library: upload_image gives one.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** A number WordPress kept as text or as a number; 0 for anything else. */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function productId(array $arguments): int
    {
        $id = self::id($arguments);
        if (get_post_type($id) !== ProductPostType::NAME) {
            throw new \InvalidArgumentException("There is no product {$id}.");
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function id(array $arguments): int
    {
        $id = $arguments['id'] ?? null;
        if (!\is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('Give id, a number from 1.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function requiredString(array $arguments, string $key, int $most): string
    {
        $value = $arguments[$key] ?? null;
        if (!\is_string($value) || trim($value) === '' || mb_strlen($value) > $most) {
            throw new \InvalidArgumentException("Give {$key}, 1 to {$most} characters.");
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function optionalString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new \InvalidArgumentException("{$key} is text.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function optionalInt(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;
        if ($value !== null && !\is_int($value)) {
            throw new \InvalidArgumentException("{$key} is a whole number.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function optionalStatus(array $arguments): ?string
    {
        $status = self::optionalString($arguments, 'status');
        if ($status !== null && !\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status is one of ' . implode(', ', self::STATUSES) . '.');
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    private static function strings(array $arguments, string $key): ?array
    {
        if (!\array_key_exists($key, $arguments)) {
            return null;
        }
        $values = $arguments[$key];
        $names = [];
        foreach (\is_array($values) ? $values : [null] as $value) {
            if (!\is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException("{$key} is a list of names.");
            }
            $names[] = trim($value);
        }

        return $names;
    }
}
