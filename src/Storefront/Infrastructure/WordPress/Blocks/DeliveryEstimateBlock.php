<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Catalog\Application\ProductSpecifications;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Shipping\Application\DeliveryEstimates;
use OrillaCart\Shipping\Application\Dto\DeliveryEstimateView;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductForBlock;
use OrillaCart\Storefront\Infrastructure\WordPress\ProductParts;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use Psr\Clock\ClockInterface;

/**
 * By when the product would arrive, ordered now: "Order within 3 h 20 min to get it by Thursday, November 6", from
 * the soonest of the carriers that deliver it to the shopper (their saved address, else the shop's country).
 * Nothing for a product that does not ship, is not for sale now or waits on backorder, nor when no carrier says
 * when it delivers. The days ahead go with the page for the script, as caches keep pages.
 */
final readonly class DeliveryEstimateBlock implements BlockDefinition
{
    /** A cutoff further off than this is not counted down to: the day is enough. */
    private const int COUNTDOWN_SECONDS = 86400;

    public function __construct(
        private ProductParts $parts,
        private ProductSpecifications $specifications,
        private DeliveryEstimates $estimates,
        private Shopper $shopper,
        private CustomerService $customers,
        private SettingsService $settings,
        private TemplateRenderer $templates,
        private ClockInterface $clock,
    ) {}

    public static function name(): string
    {
        return 'orillacart/delivery-estimate';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $id = ProductForBlock::id($attributes, $context);
        $offer = $id === null ? null : $this->parts->offer($id);
        if ($id === null || $offer === null || !$offer->forSale || $offer->onBackorder || $offer->priceHidden) {
            return '';
        }
        $product = $this->specifications->of($id);
        if ($product === null || !$product->ships) {
            return '';
        }
        $customer = $this->shopper->customerId();
        $address = $customer === null ? null : $this->customers->savedAddress($customer);
        $shop = $this->settings->current();
        $country = $address->country ?? ($shop->shopCountry === '' ? null : $shop->shopCountry);
        if ($country === null) {
            return '';
        }
        $estimates = $this->estimates->forParcel(new ShippingQuery(
            $country,
            $address !== null ? $address->state : $shop->shopState,
            [new ParcelItem(1, $product->weight, $product->length, $product->width, $product->height, $product->shippingClassId)],
            $offer->priceWithoutTax ?? $offer->price,
            $address !== null ? $address->postcode : $shop->shopPostcode,
        ));
        if ($estimates === []) {
            return '';
        }
        $days = array_map(static fn(DeliveryEstimateView $estimate): array => [
            'until' => $estimate->until->getTimestamp(),
            'order_by' => $estimate->orderBy?->getTimestamp(),
            'from' => self::day($estimate->from),
            'to' => self::day($estimate->to),
        ], $estimates);

        return $this->templates->render('storefront/_delivery_estimate.html.twig', [
            'id' => $id,
            'estimates' => $days,
            'text' => self::text($days[0], $this->clock->now()->getTimestamp()),
        ]);
    }

    /**
     * What the shopper reads, as the script writes it too (assets/storefront/delivery.ts).
     *
     * @param array{until: int, order_by: ?int, from: string, to: string} $estimate
     */
    private static function text(array $estimate, int $now): string
    {
        $left = $estimate['order_by'] === null ? null : $estimate['order_by'] - $now;
        if ($left !== null && $left > 0 && $left <= self::COUNTDOWN_SECONDS) {
            $minutes = (int) ceil($left / 60);
            $time = $minutes >= 60
                /* translators: 1: hours, 2: minutes, a time left to order in */
                ? \sprintf(__('%1$d h %2$d min', 'orillacart'), intdiv($minutes, 60), $minutes % 60)
                /* translators: %d: minutes, a time left to order in */
                : \sprintf(__('%d min', 'orillacart'), $minutes);

            return $estimate['from'] === $estimate['to']
                /* translators: 1: a time left to order in, such as 3 h 20 min, 2: a day, such as Thursday, November 6 */
                ? \sprintf(__('Order within %1$s to get it by %2$s', 'orillacart'), $time, $estimate['to'])
                /* translators: 1: a time left to order in, such as 3 h 20 min, 2: the earliest day, 3: the latest */
                : \sprintf(__('Order within %1$s to get it between %2$s and %3$s', 'orillacart'), $time, $estimate['from'], $estimate['to']);
        }

        return $estimate['from'] === $estimate['to']
            /* translators: %s: a day, such as Thursday, November 6 */
            ? \sprintf(__('Get it by %s', 'orillacart'), $estimate['to'])
            /* translators: 1: the earliest day, such as Tuesday, November 4, 2: the latest */
            : \sprintf(__('Get it between %1$s and %2$s', 'orillacart'), $estimate['from'], $estimate['to']);
    }

    /** "Thursday, November 6", in the shopper's language; the day is the shop's. */
    private static function day(string $date): string
    {
        $day = new \DateTimeImmutable($date . ' 12:00', wp_timezone());

        /* translators: how a day of delivery is written, in PHP's date() letters: "l, F j" for Thursday, November 6 */
        return (string) wp_date(__('l, F j', 'orillacart'), $day->getTimestamp(), wp_timezone());
    }
}
