<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\PricedItem;
use OrillaCart\Catalog\Domain\ShopperPrices;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Pricing\PriceQuery;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Extension\ExtensionFailures;
use Psr\Log\LoggerInterface;

/**
 * An item's own price for one shopper: the price rules that hold for them, then each add-on's adjuster in turn,
 * rounded to the shop's decimals, so the price shown is the price charged. Options and bundles price on top of it.
 */
final readonly class ShopperPricing
{
    /**
     * @param list<PriceAdjuster> $adjusters
     */
    public function __construct(
        private ShopperPrices $rules,
        private array $adjusters,
        private ?int $customerId,
        private string $currency,
        private LoggerInterface $logger,
        private int $decimals = Money::DECIMALS,
        private ExtensionFailures $failures = new ExtensionFailures(),
    ) {}

    /**
     * The price of each of this many items.
     *
     * @param Money $price   today's price, on sale or not
     * @param Money $regular the price without a sale; today's when null
     */
    public function price(Money $price, PricedItem $item, int $quantity = 1, ?int $variationId = null, ?Money $regular = null): Money
    {
        return $this->priced($price, $item, $quantity, $variationId, $regular)[0];
    }

    /**
     * The price of each of this many items, and whether codes and automatic discounts still come off it.
     *
     * @return array{Money, bool}
     */
    public function priced(Money $price, PricedItem $item, int $quantity = 1, ?int $variationId = null, ?Money $regular = null): array
    {
        [$ruled, $coupons] = $this->rules->priced($price, $item, $quantity);

        return [$this->adjusted($ruled, $item->productId, $variationId, $quantity, $regular ?? $price)->rounded($this->decimals), $coupons];
    }

    /**
     * The price from each quantity where a rule changes it, for the product page.
     *
     * @return list<array{min_quantity: int, price: string}>
     */
    public function tiers(Money $price, PricedItem $item, ?int $variationId = null, ?Money $regular = null): array
    {
        return $this->rules->tiers($price, $item, fn(Money $ruled, int $quantity): Money => $this->adjusted($ruled, $item->productId, $variationId, $quantity, $regular ?? $price)->rounded($this->decimals));
    }

    public function nextChange(PricedItem $item): ?\DateTimeImmutable
    {
        return $this->rules->nextChange($item);
    }

    private function adjusted(Money $price, int $productId, ?int $variationId, int $quantity, Money $regular): Money
    {
        foreach ($this->adjusters as $adjuster) {
            try {
                $answer = $adjuster->adjust(new PriceQuery($productId, $variationId, $quantity, $this->customerId, (string) $price, (string) $regular, $this->currency));
            } catch (\Throwable $e) {
                $this->logger->error('The price adjuster {adjuster} failed for product {product}: {message}', ['adjuster' => $adjuster->id(), 'product' => $productId, 'message' => $e->getMessage(), 'exception' => $e]);
                $this->failures->record(\sprintf('The price adjuster %s failed for product %d; it was priced without it.', $adjuster->id(), $productId), $adjuster instanceof FailsClosed);

                continue;
            }
            if ($answer === null) {
                continue;
            }
            if (preg_match('/^-?\d{1,11}(\.\d{1,' . Money::DECIMALS . '})?$/', $answer) !== 1) {
                $this->logger->error('The price adjuster {adjuster} answered "{answer}" for product {product}, which is not a price; it is ignored.', ['adjuster' => $adjuster->id(), 'answer' => $answer, 'product' => $productId]);
                $this->failures->record(\sprintf('The price adjuster %s answered "%s" for product %d, which is not a price; it was priced without it.', $adjuster->id(), mb_substr($answer, 0, 40), $productId), $adjuster instanceof FailsClosed);

                continue;
            }
            $price = Money::of($answer)->atLeastZero();
        }

        return $price;
    }
}
