<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Catalog\Infrastructure\WordPress\Hooks\ProductGallery;
use OrillaCart\Checkout\Application\ShownPrices;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Reviews\Application\Dto\ReviewInvitationView;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Storefront\Application\StorefrontSettingsService;

/**
 * The parts of a product's page, each rendered on the server: its pictures, price, the way to buy it, the
 * products beside it and its reviews. The product blocks show one part each wherever the template puts it; the
 * content filter (ProductPanel) shows them all below the description where the template has none of them.
 * Each part names its product (data-orillacart-for), so the script finds the price and the pictures to change
 * with the chosen variation anywhere on the page. Offers are kept for the request: a page shows several parts of
 * one product.
 */
final class ProductParts
{
    /** The block that holds the way to buy: a template with it shows the parts as blocks. */
    public const string FORM_BLOCK = 'orillacart/add-to-cart';

    /** @var array<int, ProductOffer|null> */
    private array $offers = [];
    /** @var array<int, array<string, mixed>|null> */
    private array $cards = [];

    public function __construct(
        private readonly ShopCatalog $catalog,
        private readonly ShopLinks $links,
        private readonly TemplateRenderer $templates,
        private readonly Shopper $shopper,
        private readonly EmailSettingsRepository $emails,
        private readonly Reviews $reviews,
        private readonly Merchandise $merchandise,
        private readonly ProductCards $productCards,
        private readonly StorefrontSettingsService $storefront,
        private readonly AnalyticsEvents $analytics,
        private readonly ShopForms $forms,
        private readonly ProductContent $content,
        private readonly ShownPrices $shownPrices,
    ) {}

    /**
     * The product as the shopper is offered it now; null for one that is not a product, or not for show (a draft
     * shows only to those who may edit it).
     */
    public function offer(int $id): ?ProductOffer
    {
        if (get_post_type($id) !== ProductPostType::NAME || (get_post_status($id) !== 'publish' && !current_user_can('edit_post', $id))) {
            return null;
        }
        if (!\array_key_exists($id, $this->offers)) {
            try {
                $this->offers[$id] = $this->shownPrices->offer($this->catalog->offer($id, $this->shopper->customerId()), $this->shopper->customerId());
            } catch (ProductNotFound) {
                $this->offers[$id] = null;
            }
        }

        return $this->offers[$id];
    }

    /**
     * The product as the shop's cards show it (ProductCards); null for one not for show.
     *
     * @return array<string, mixed>|null
     */
    public function card(int $id): ?array
    {
        if (!\array_key_exists($id, $this->cards)) {
            $this->warm([$id]);
        }

        return $this->cards[$id] ?? null;
    }

    /**
     * Reads the cards of a list's products at once, which the blocks of each card then show.
     *
     * @param list<int> $ids
     */
    public function warm(array $ids): void
    {
        $ids = array_values(array_filter($ids, fn(int $id): bool => !\array_key_exists($id, $this->cards)));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            $this->cards[$id] = null;
        }
        foreach ($this->productCards->cards($ids) as $card) {
            if ($card['offer'] instanceof ProductOffer) {
                $this->cards[$card['offer']->id] = $card;
            }
        }
    }

    /** The shop's cart page, where the forms post; null without one. */
    public function cartUrl(): ?string
    {
        return $this->links->pages()['cart'];
    }

    /**
     * Whether the page's block template shows the product's parts as blocks, so the content filter adds none.
     */
    public static function templateHasBlocks(): bool
    {
        global $_wp_current_template_content;

        return \is_string($_wp_current_template_content) && has_block(self::FORM_BLOCK, $_wp_current_template_content);
    }

    /** All of them below the description, for a page whose template has none of the product blocks. */
    public function whole(int $id): string
    {
        $offer = $this->offer($id);
        if ($offer === null) {
            return '';
        }
        $recentlyViewed = $this->storefront->current()->recentlyViewed;

        return $this->templates->render('storefront/product.html.twig', $this->formVariables($offer) + [
            'gallery' => $this->images($id),
            'recently_viewed' => $recentlyViewed,
        ]) . $this->analytics->viewItem($id, $offer)
            . $this->row($id, 'upsells') . $this->row($id, 'related')
            . ($recentlyViewed ? $this->recentlyViewed($id) : '')
            . $this->reviews($id);
    }

    /**
     * The large picture and the thumbnails; nothing for a product without pictures.
     */
    public function gallery(int $id): string
    {
        $images = $this->offer($id) === null ? [] : $this->images($id);
        if ($images === []) {
            return '';
        }
        // The page's main picture, in the first response: what search engines and the largest paint see.
        $stage = wp_get_attachment_image($images[0]['id'], 'large', false, ['class' => 'orillacart-gallery__image', 'loading' => false, 'fetchpriority' => 'high']);

        return \sprintf('<div class="orillacart-product-gallery" data-orillacart-for="%d">%s</div>', $id, $this->templates->render('storefront/_product_gallery.html.twig', [
            'gallery' => $images,
            'stage_image' => $stage,
        ]));
    }

    public function price(int $id, bool $tiers = true): string
    {
        $offer = $this->offer($id);
        if ($offer === null) {
            return '';
        }

        return \sprintf('<div class="orillacart-product-price" data-orillacart-for="%d">%s</div>', $id, $this->templates->render('storefront/_product_price.html.twig', [
            'offer' => $offer,
            'show_tiers' => $tiers,
        ]));
    }

    /**
     * The way to buy it. On the product's own page it marks the product viewed (recently viewed, analytics).
     *
     * @param array{contents?: bool, sku?: bool, stock?: bool, wishlist?: bool, downloads?: bool} $show
     */
    public function form(int $id, array $show = []): string
    {
        $offer = $this->offer($id);
        if ($offer === null) {
            return '';
        }
        $ownPage = is_singular(ProductPostType::NAME) && get_queried_object_id() === $id;
        $viewed = $ownPage && $this->storefront->current()->recentlyViewed;

        return \sprintf(
            '<section class="orillacart-product orillacart-product--form" aria-label="%s" data-orillacart-for="%d"%s>%s</section>%s',
            esc_attr__('Buy', 'orillacart'),
            $id,
            $viewed ? \sprintf(' data-orillacart-viewed="%d"', $id) : '',
            $this->templates->render('storefront/_product_form.html.twig', $this->formVariables($offer) + ['show' => $show]),
            $ownPage ? $this->analytics->viewItem($id, $offer) : '',
        );
    }

    /**
     * A row of cards: the products the shop offers instead (upsells), or those like it (related).
     *
     * @param 'upsells'|'related' $kind
     */
    public function row(int $id, string $kind, ?string $title = null, int $count = Merchandise::SHOWN): string
    {
        $count = max(1, $count);
        $ids = $kind === 'upsells' ? $this->merchandise->upsells($id, $count) : $this->merchandise->related($id, $count);

        return $this->templates->render('storefront/_product_row.html.twig', [
            'title' => $title ?? ($kind === 'upsells' ? __('You may also like', 'orillacart') : __('Related products', 'orillacart')),
            'products' => $this->productCards->cards($ids),
            'cart_url' => $this->links->pages()['cart'],
            'class' => $kind === 'upsells' ? 'orillacart-upsells' : 'orillacart-related',
        ]);
    }

    public function recentlyViewed(int $id): string
    {
        return $this->templates->render('storefront/_recently_viewed.html.twig', ['count' => Merchandise::SHOWN, 'exclude' => $id]);
    }

    /**
     * The product's reviews and the form to write one; nothing while the shop takes no reviews, or the product
     * takes none and has none.
     */
    public function reviews(int $id): string
    {
        if (!$this->reviews->shown() || $this->offer($id) === null) {
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
     * The stars and the number of reviews, linking to them; nothing for a product without reviews.
     */
    public function rating(int $id): string
    {
        $rating = $this->offer($id) === null || !$this->reviews->shown() ? null : ($this->reviews->ratings([$id])[$id] ?? null);
        if ($rating === null) {
            return '';
        }
        $ownPage = is_singular(ProductPostType::NAME) && get_queried_object_id() === $id;

        return $this->templates->render('storefront/_product_rating.html.twig', [
            'rating' => $rating,
            'url' => ($ownPage ? '' : (string) get_permalink($id)) . '#reviews',
        ]);
    }

    /**
     * What the form and the script need.
     *
     * @return array<string, mixed>
     */
    private function formVariables(ProductOffer $offer): array
    {
        $storefront = $this->storefront->current();

        return [
            'offer' => $offer,
            'variations' => array_map(fn(OfferedVariation $variation): array => [
                'id' => $variation->id,
                'option_ids' => $variation->optionIds,
                'sku' => $variation->sku,
                'for_sale' => $variation->forSale,
                'available' => $variation->available,
                'on_backorder' => $variation->onBackorder,
                'sold_out' => $variation->soldOut,
                'price' => $this->templates->render('storefront/_variation_price.html.twig', ['variation' => $variation]),
                'image' => $this->images([(int) get_post_thumbnail_id($variation->id)])[0] ?? null,
                'gallery' => $this->images(ProductGallery::of($variation->id)),
                'description' => wp_strip_all_tags((string) get_post_field('post_excerpt', $variation->id)),
            ], $offer->variations),
            'cart_url' => $this->links->pages()['cart'],
            'back_in_stock' => $this->emails->load()->sends(EmailKind::BackInStock),
            'email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'wishlist' => $storefront->wishlist->value,
            'personalisation' => $this->forms->productFields($offer->id, $this->content->termIds($offer->id)['categories']),
        ];
    }

    /**
     * Media library images as the gallery and the script show them: the large picture and a thumbnail. With a
     * product's id: its featured image, then its gallery.
     *
     * @param int|list<int> $ids
     *
     * @return list<array{id: int, src: string, srcset: string, sizes: string, alt: string, thumb: string}>
     */
    private function images(int|array $ids): array
    {
        if (\is_int($ids)) {
            $ids = [(int) get_post_thumbnail_id($ids), ...ProductGallery::of($ids)];
        }
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
}
