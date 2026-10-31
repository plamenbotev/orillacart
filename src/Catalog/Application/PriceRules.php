<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\PriceRuleView;
use OrillaCart\Catalog\Application\Dto\PriceTierData;
use OrillaCart\Catalog\Application\Dto\SavePriceRule;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\PriceRulesChanged;
use OrillaCart\Catalog\Domain\PriceRule;
use OrillaCart\Catalog\Domain\PriceRuleNotFound;
use OrillaCart\Catalog\Domain\PriceRuleRepository;
use OrillaCart\Catalog\Domain\PriceTier;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Shared\Domain\Money;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The price rules the shop sets up: prices for customer groups, by quantity, between dates. Placed orders keep
 * the prices they were placed at.
 */
final readonly class PriceRules
{
    public function __construct(
        private PriceRuleRepository $rules,
        private EventDispatcherInterface $events,
        private ProductTranslations $translations,
        private ProductContent $content,
    ) {}

    private function view(PriceRule $rule): PriceRuleView
    {
        return PriceRuleView::fromRule($rule, array_values(array_filter($rule->productIds(), fn(int $id): bool => !$this->content->exists($id))));
    }

    /** @return list<PriceRuleView> by name */
    public function rules(): array
    {
        return array_map($this->view(...), $this->rules->all());
    }

    /**
     * @throws CatalogRuleViolation
     */
    public function create(SavePriceRule $command): PriceRuleView
    {
        $rule = new PriceRule(...$this->arguments($command));
        $this->rules->save($rule);
        $this->events->dispatch(new PriceRulesChanged([$rule->scope()]));

        return $this->view($rule);
    }

    /**
     * @throws PriceRuleNotFound
     * @throws CatalogRuleViolation
     */
    public function update(int $id, SavePriceRule $command): PriceRuleView
    {
        $rule = $this->get($id);
        $before = $rule->scope();
        $rule->change(...$this->arguments($command));
        $this->rules->save($rule);
        $this->events->dispatch(new PriceRulesChanged([$before, $rule->scope()]));

        return $this->view($rule);
    }

    /**
     * @throws PriceRuleNotFound
     */
    public function delete(int $id): void
    {
        $rule = $this->get($id);
        $this->rules->delete($rule);
        $this->events->dispatch(new PriceRulesChanged([$rule->scope()]));
    }

    private function get(int $id): PriceRule
    {
        return $this->rules->find($id) ?? throw new PriceRuleNotFound($id);
    }

    /**
     * @return array{string, list<PriceTier>, list<int>, list<int>, list<int>, list<int>, ?\DateTimeImmutable, ?\DateTimeImmutable, bool, bool, bool, bool}
     */
    private function arguments(SavePriceRule $command): array
    {
        $moment = static fn(?string $at): ?\DateTimeImmutable => $at === null || $at === '' ? null : new \DateTimeImmutable($at)->setTimezone(new \DateTimeZone('UTC'));
        $ids = static fn(array $ids): array => array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));

        return [
            $command->name,
            array_map(static fn(PriceTierData $tier): PriceTier => new PriceTier($tier->minQuantity, $tier->kind, Money::of($tier->value)), $command->tiers),
            $ids($command->groupIds),
            // Products picked in the admin's language are their originals, the products (design 51).
            $this->translations->originals($ids($command->productIds)),
            $ids($command->categoryIds),
            $ids($command->brandIds),
            $moment($command->startsAt),
            $moment($command->endsAt),
            $command->withSales,
            $command->active,
            $command->withCoupons,
            $command->combines,
        ];
    }
}
