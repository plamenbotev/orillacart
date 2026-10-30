<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceRuleRepository;
use OrillaCart\Catalog\Domain\ShopperPrices;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The prices one shopper pays: the shop's price rules for their customer groups, then the add-ons' adjusters.
 */
final class PriceResolver
{
    /** @var array<string, array{list<PriceRule>, ShopperPricing}> each shopper's prices made in this request, with the rules they were made from */
    private array $made = [];

    public function __construct(
        private readonly PriceRuleRepository $rules,
        private readonly ShopperGroups $groups,
        private readonly ClockInterface $clock,
        private readonly InstalledExtensions $extensions,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly ExtensionFailures $failures,
    ) {}

    /**
     * Made once per request for each shopper, as a cart prices each of its lines with them: made anew when what
     * they are made of changes (the rules, the shopper's groups, the add-ons, the currency) or the minute does.
     *
     * @param int|null $customerId null for a guest, and for the search index
     */
    public function forShopper(?int $customerId): ShopperPricing
    {
        $rules = $this->rules->active();
        $groups = $this->groups->groupIdsOf($customerId);
        $adjusters = $this->extensions->priceAdjusters();
        $settings = $this->settings->current();
        $now = $this->clock->now();
        $key = implode('|', [
            (string) $customerId,
            implode(',', $groups),
            implode(',', array_map(spl_object_id(...), $adjusters)),
            $settings->currency,
            (string) $settings->priceDecimals,
            $now->format('Y-m-d H:i e'),
        ]);
        $made = $this->made[$key] ?? null;
        if ($made !== null && $made[0] === $rules) {
            return $made[1];
        }
        $pricing = new ShopperPricing(new ShopperPrices($rules, $groups, $now), $adjusters, $customerId, $settings->currency, $this->logger, $settings->priceDecimals, $this->failures);
        // One a shopper: what is made anew replaces what was.
        $this->made = array_filter($this->made, static fn(string $made): bool => !str_starts_with($made, $customerId . '|'), ARRAY_FILTER_USE_KEY);
        $this->made[$key] = [$rules, $pricing];

        return $pricing;
    }
}
