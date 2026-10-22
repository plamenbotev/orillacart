<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Reviews\Application\Dto\ReviewInvitationView;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Infrastructure\WordPress\AnalyticsEvents;
use OrillaCart\Storefront\Infrastructure\WordPress\Merchandise;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductCards;
use OrillaCart\Storefront\Infrastructure\WordPress\ReviewLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * Puts the buying form, the products to buy instead or besides, and the reviews under a product's description on its page, and its price under it in
 * lists of posts, such as category pages. Through the content filter, so classic and block themes both show it.
 */
#[AsHook('the_content', method: 'content', filter: true, priority: 20)]
#[AsHook('get_the_excerpt', method: 'excerpt', filter: true, priority: 20, arguments: 2)]
final readonly class ProductPanel
{
    public function __construct(
        private ShopCatalog $catalog,
        private ShopLinks $links,
        private TemplateRenderer $templates,
        private Shopper $shopper,
        private EmailSettingsRepository $emails,
        private Reviews $reviews,
        private Merchandise $merchandise,
        private ProductCards $cards,
        private StorefrontSettingsService $storefront,
        private AnalyticsEvents $analytics,
        private ShopForms $forms,
        private ProductContent $content,
    ) {}

    public function content(string $content): string
    {
        $id = get_the_ID();
        if (!\is_int($id) || get_post_type($id) !== ProductPostType::NAME || doing_filter('get_the_excerpt')) {
            return $content;
        }
        if (!is_singular(ProductPostType::NAME) || $id !== get_queried_object_id()) {
            return $content . $this->price($id);
        }
        try {
            $offer = $this->catalog->offer($id, $this->shopper->customerId());
        } catch (ProductNotFound) {
            return $content;
        }

        $storefront = $this->storefront->current();

        return $content . $this->templates->render('storefront/product.html.twig', [
            'offer' => $offer,
            'gallery' => self::images([(int) get_post_thumbnail_id($id), ...ProductGallery::of($id)]),
            'variations' => array_map(fn(OfferedVariation $variation): array => [
                'id' => $variation->id,
                'option_ids' => $variation->optionIds,
                'sku' => $variation->sku,
                'for_sale' => $variation->forSale,
                'available' => $variation->available,
                'on_backorder' => $variation->onBackorder,
                'sold_out' => $variation->soldOut,
                'price' => $this->templates->render('storefront/_variation_price.html.twig', ['variation' => $variation]),
                'image' => self::images([(int) get_post_thumbnail_id($variation->id)])[0] ?? null,
                'gallery' => self::images(ProductGallery::of($variation->id)),
                'description' => wp_strip_all_tags((string) get_post_field('post_excerpt', $variation->id)),
            ], $offer->variations),
            'cart_url' => $this->links->pages()['cart'],
            'back_in_stock' => $this->emails->load()->sends(EmailKind::BackInStock),
            'email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'wishlist' => $storefront->wishlist->value,
            'recently_viewed' => $storefront->recentlyViewed,
            'personalisation' => $this->forms->productFields($id, $this->content->termIds($id)['categories']),
        ]) . $this->analytics->viewItem($id, $offer) . $this->beside($id)
            . ($storefront->recentlyViewed ? $this->templates->render('storefront/_recently_viewed.html.twig', ['count' => Merchandise::SHOWN, 'exclude' => $id]) : '')
            . $this->reviews($id);
    }

    /**
     * The products the shop offers instead (upsells), and those like it.
     */
    private function beside(int $id): string
    {
        $cart = $this->links->pages()['cart'];

        return $this->templates->render('storefront/_product_row.html.twig', [
            'title' => __('You may also like', 'orillacart'),
            'products' => $this->cards->cards($this->merchandise->upsells($id)),
            'cart_url' => $cart,
            'class' => 'orillacart-upsells',
        ]) . $this->templates->render('storefront/_product_row.html.twig', [
            'title' => __('Related products', 'orillacart'),
            'products' => $this->cards->cards($this->merchandise->related($id)),
            'cart_url' => $cart,
            'class' => 'orillacart-related',
        ]);
    }

    /**
     * The product's reviews and the form to write one; nothing while the shop takes no reviews, or the product
     * takes none and has none.
     */
    private function reviews(int $id): string
    {
        if (!$this->reviews->shown()) {
            return '';
        }
        $receipt = $_GET[ReviewLinks::QUERY] ?? null;
        $receipt = \is_string($receipt) && preg_match('/^' . ReceiptLinks::PATTERN . '$/', $receipt) === 1 ? $receipt : null;
        $invitation = $this->reviews->invitation($id, $this->shopper->customerId(), $receipt);
        $number = $_GET['review-page'] ?? '1';
        $page = $this->reviews->page($id, \is_string($number) && ctype_digit($number) ? (int) $number : 1);
        if ($page->rating === null && !$invitation->open && \in_array($invitation->reason, [ReviewInvitationView::OFF, ReviewInvitationView::CLOSED], true)) {
            return '';
        }
        $pageUrls = [];
        for ($number = 1; $number <= $page->pages; ++$number) {
            $pageUrls[$number] = $number === 1 ? remove_query_arg('review-page') : add_query_arg('review-page', $number);
        }

        return $this->templates->render('storefront/reviews.html.twig', [
            'product_id' => $id,
            'page' => $page,
            'page_urls' => $pageUrls,
            'invitation' => $invitation,
            'receipt' => $invitation->open ? $receipt : null,
            'account_url' => $this->links->pages()['account'] ?? '',
        ]);
    }

    /**
     * Media library images as the gallery and the script show them: the large picture and a thumbnail.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int, src: string, srcset: string, sizes: string, alt: string, thumb: string}>
     */
    private static function images(array $ids): array
    {
        $images = [];
        foreach (array_unique($ids) as $id) {
            $large = $id > 0 ? wp_get_attachment_image_src($id, 'large') : false;
            $thumb = $id > 0 ? wp_get_attachment_image_src($id, 'thumbnail') : false;
            if ($large === false || $thumb === false) {
                continue;
            }
            $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
            $images[] = [
                'id' => $id,
                'src' => $large[0],
                'srcset' => (string) wp_get_attachment_image_srcset($id, 'large'),
                'sizes' => (string) wp_get_attachment_image_sizes($id, 'large'),
                'alt' => \is_string($alt) ? $alt : '',
                'thumb' => $thumb[0],
            ];
        }

        return $images;
    }

    public function excerpt(string $excerpt, ?\WP_Post $post = null): string
    {
        if ($post === null || $post->post_type !== ProductPostType::NAME || is_singular(ProductPostType::NAME)) {
            return $excerpt;
        }

        return $excerpt . ' ' . wp_strip_all_tags($this->price($post->ID));
    }

    private function price(int $id): string
    {
        $offers = $this->catalog->offers([$id], $this->shopper->customerId());

        return isset($offers[$id]) ? $this->templates->render('storefront/_list_price.html.twig', ['offer' => $offers[$id]]) : '';
    }
}
