<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Reviews\Infrastructure\WordPress\CommentReviewStore;
use OrillaCart\Reviews\Infrastructure\WordPress\OptionReviewSettingsRepository;

/**
 * Reviews in a real WordPress: comments of their own type with their stars, moderated by the site's discussion
 * settings, shown under the product's buying form instead of the theme's comments, which take none.
 */
final class ReviewsTest extends RestTestCase
{
    private int $lamp;
    /** @var array<string, mixed> */
    private array $options = [];
    private \Closure $limits;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['comment_moderation', 'comment_previously_approved', 'comment_registration', OptionReviewSettingsRepository::OPTION] as $option) {
            $this->options[$option] = get_option($option);
        }
        // As Settings → Discussion saves them: WordPress compares them as strings.
        update_option('comment_moderation', '0');
        update_option('comment_previously_approved', '0');
        update_option('comment_registration', '0');
        delete_option(OptionReviewSettingsRepository::OPTION);
        // Tests write more reviews an hour than a visitor may.
        $this->limits = static fn(array $limits): array => ['reviews' => [1000, 3600]] + $limits;
        add_filter('orillacart_rate_limits', $this->limits, 20);
        // Two reviews within a second are a flood to WordPress.
        add_filter('comment_flood_filter', '__return_false', 20);

        $this->actAs('administrator');
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Brass lamp', 'post_status' => 'publish', 'comment_status' => 'open']);
        self::assertIsInt($id);
        $this->lamp = $id;
        self::assertSame(200, $this->request('PUT', "/products/{$id}", ['price' => '40', 'stock_mode' => 'always_available'], nonce: true)->get_status());
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        wp_delete_post($this->lamp, true);
        foreach ($this->options as $option => $value) {
            $value === false ? delete_option($option) : update_option($option, $value);
        }
        remove_filter('orillacart_rate_limits', $this->limits, 20);
        remove_filter('comment_flood_filter', '__return_false', 20);
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_products");
        parent::tearDown();
    }

    public function testAGuestsReviewIsACommentWithItsStarsAndTheProductPageShowsIt(): void
    {
        $written = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 4, 'text' => "Warm light.\nGood for reading.", 'name' => 'Bob', 'email' => 'bob@example.com'], nonce: true);
        self::assertSame(201, $written->get_status(), (string) wp_json_encode($written->get_data()));
        self::assertSame('published', $this->data($written)['status']);

        $comments = get_comments(['post_id' => $this->lamp, 'type' => CommentReviewStore::TYPE]);
        self::assertIsArray($comments);
        self::assertCount(1, $comments);
        $comment = $comments[0];
        self::assertInstanceOf(\WP_Comment::class, $comment);
        self::assertSame(['Bob', 'bob@example.com', '4', '0'], [$comment->comment_author, $comment->comment_author_email, get_comment_meta((int) $comment->comment_ID, CommentReviewStore::RATING, true), get_comment_meta((int) $comment->comment_ID, CommentReviewStore::VERIFIED, true)]);

        $again = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 1, 'text' => 'Changed my mind.', 'name' => 'Bob', 'email' => 'bob@example.com'], nonce: true);
        self::assertSame([422, 'orillacart_already_reviewed'], [$again->get_status(), $this->data($again)['code']]);
        wp_spam_comment((int) $comment->comment_ID);
        $spammed = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 1, 'text' => 'Buy cheap watches.', 'name' => 'Bob', 'email' => 'bob@example.com'], nonce: true);
        self::assertSame('orillacart_already_reviewed', $this->data($spammed)['code'] ?? null, 'marked spam, not a new reviewer');
        wp_unspam_comment((int) $comment->comment_ID);

        // The shop answers from the Comments screen.
        $this->actAs('administrator');
        wp_insert_comment(['comment_post_ID' => $this->lamp, 'comment_parent' => (int) $comment->comment_ID, 'comment_content' => 'Thank you, Bob!', 'comment_author' => 'The shop', 'comment_approved' => 1]);
        wp_set_current_user(0);

        $page = $this->data($this->request('GET', "/products/{$this->lamp}/reviews"));
        self::assertIsArray($page['rating']);
        self::assertSame(['4.0', 1], [$page['rating']['average'], $page['rating']['count']]);
        self::assertIsArray($page['reviews']);
        self::assertIsArray($page['reviews'][0]);
        $replies = $page['reviews'][0]['replies'];
        self::assertIsArray($replies);
        self::assertIsArray($replies[0]);
        self::assertSame('Thank you, Bob!', $replies[0]['text']);

        $html = $this->productPage();
        self::assertStringContainsString('id="reviews"', $html);
        self::assertStringContainsString('Warm light.<br />', $html, 'the words as written, their lines kept');
        self::assertStringContainsString('<strong class="orillacart-review__author">Bob</strong>', $html);
        self::assertStringContainsString('Thank you, Bob!', $html);
        self::assertStringContainsString('data-orillacart-review-form', $html, 'another visitor may review it too');
        self::assertStringContainsString('/templates/wordpress/no-comments.php', $this->productPage(static fn(): mixed => apply_filters('comments_template', 'comments.php')), 'the theme\'s comments do not show');
    }

    public function testACustomerWithoutANameIsSignedCustomerInTheSitesLanguage(): void
    {
        $id = $this->actAs(WordPressAccounts::ROLE);
        wp_update_user(['ID' => $id, 'first_name' => '', 'last_name' => '', 'user_email' => 'nameless@example.com']);

        $written = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 5, 'text' => 'Bright.'], nonce: true);
        self::assertSame(201, $written->get_status(), (string) wp_json_encode($written->get_data()));
        $comments = get_comments(['post_id' => $this->lamp, 'type' => CommentReviewStore::TYPE, 'user_id' => $id]);
        self::assertIsArray($comments);
        self::assertInstanceOf(\WP_Comment::class, $comments[0] ?? null);
        self::assertSame('Customer', $comments[0]->comment_author);
        wp_set_current_user(0);
    }

    public function testTheSitesDiscussionSettingsHoldReviewsAndMayAskGuestsToLogIn(): void
    {
        update_option('comment_moderation', '1');
        $held = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 5, 'text' => 'Lovely.', 'name' => 'Eve', 'email' => 'eve@example.com'], nonce: true);
        self::assertSame('held', $this->data($held)['status']);
        self::assertNull($this->data($this->request('GET', "/products/{$this->lamp}/reviews"))['rating'], 'a review waiting for approval counts for nothing');

        update_option('comment_registration', '1');
        $guest = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 5, 'text' => 'Lovely.', 'name' => 'Ann', 'email' => 'ann@example.com'], nonce: true);
        self::assertSame('orillacart_review_log_in', $this->data($guest)['code']);
        self::assertStringContainsString('Log in to review this product.', $this->productPage());
    }

    public function testTheShopMayTakeNoReviewsAndAProductMayCloseItsOwn(): void
    {
        wp_update_post(['ID' => $this->lamp, 'comment_status' => 'closed']);
        $closed = $this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 5, 'text' => 'Lovely.', 'name' => 'Eve', 'email' => 'eve@example.com'], nonce: true);
        self::assertSame('orillacart_reviews_closed', $this->data($closed)['code']);
        self::assertStringNotContainsString('id="reviews"', $this->productPage(), 'no reviews and none to take');

        wp_update_post(['ID' => $this->lamp, 'comment_status' => 'open']);
        $this->actAs('administrator');
        self::assertSame(200, $this->request('PUT', '/review-settings', ['policy' => 'off'], nonce: true)->get_status());
        wp_set_current_user(0);
        self::assertSame('orillacart_reviews_off', $this->data($this->request('POST', "/products/{$this->lamp}/reviews", ['rating' => 5, 'text' => 'Lovely.', 'name' => 'Eve', 'email' => 'eve@example.com'], nonce: true))['code']);
        self::assertStringNotContainsString('id="reviews"', $this->productPage());
    }

    public function testTheThemesCommentFormTakesNoCommentOnAProduct(): void
    {
        $died = static function (): \Closure {
            return static function (string $message): never {
                throw new \RuntimeException($message);
            };
        };
        add_filter('wp_die_handler', $died);
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        try {
            wp_handle_comment_submission(['comment_post_ID' => $this->lamp, 'comment' => 'A plain comment.', 'author' => 'Mallory', 'email' => 'mallory@example.com']);
            self::fail('the theme\'s form commented on a product');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Review the product from its page.', $e->getMessage());
        } finally {
            remove_filter('wp_die_handler', $died);
        }
        self::assertSame(0, (int) get_comments(['post_id' => $this->lamp, 'count' => true]));
    }

    /**
     * The product's page, or what $render returns while WordPress shows it.
     *
     * @param (\Closure(): mixed)|null $render
     */
    private function productPage(?\Closure $render = null): string
    {
        global $wp_query, $wp_the_query, $post;
        $wp_query = new \WP_Query(['p' => $this->lamp, 'post_type' => ProductPostType::NAME]);
        $wp_the_query = $wp_query;
        $wp_query->the_post();
        try {
            $html = $render !== null ? $render() : apply_filters('the_content', '');
            self::assertIsString($html);

            return $html;
        } finally {
            wp_reset_postdata();
            $wp_query = $wp_the_query = new \WP_Query();
            $post = null;
        }
    }
}
