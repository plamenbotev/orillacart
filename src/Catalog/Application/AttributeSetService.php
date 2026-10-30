<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeSetView;
use OrillaCart\Catalog\Application\Dto\SaveAttributeSet;
use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetNotFound;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\Event\CatalogItemsRemoved;
use OrillaCart\Catalog\Domain\Event\ProductChanged;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Translations\Application\TranslationPackages;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for attribute sets, as the Attribute sets admin screen runs them.
 */
final readonly class AttributeSetService
{
    public function __construct(
        private AttributeSetRepository $sets,
        private ProductRepository $products,
        private EventDispatcherInterface $events,
        private AttributeTexts $texts,
        private Transactions $transactions,
        private TranslationPackages $packages,
    ) {}

    /**
     * @return list<AttributeSetView> by name
     */
    public function sets(): array
    {
        $sets = $this->sets->all();
        $texts = $this->texts->of(array_merge(...array_map(static fn(AttributeSet $set): array => $set->ownAttributes(), $sets)));

        return array_map(static fn(AttributeSet $set): AttributeSetView => AttributeSetView::fromSet($set, $texts), $sets);
    }

    /**
     * @throws AttributeSetNotFound
     */
    public function set(int $id): AttributeSetView
    {
        $set = $this->get($id);

        return AttributeSetView::fromSet($set, $this->texts->of($set->ownAttributes()));
    }

    public function create(SaveAttributeSet $command): AttributeSetView
    {
        $texts = $this->texts->check($command->attributes);
        $set = new AttributeSet($command->name, $command->active);
        $set->replaceAttributes(CatalogDrafts::attributes($command->attributes));
        $this->transactions->run(function () use ($set, $texts): void {
            $this->sets->save($set);
            $this->texts->write($texts, $set->ownAttributes(), [], []);
        });
        $this->packages->changed(AttributePackages::KIND, $set->id());

        return AttributeSetView::fromSet($set, $this->texts->of($set->ownAttributes()));
    }

    /**
     * @throws AttributeSetNotFound
     * @throws CatalogRuleViolation when a variation uses an option this would remove, or of a set turned off
     */
    public function update(int $id, SaveAttributeSet $command): AttributeSetView
    {
        $set = $this->get($id);
        $drafts = CatalogDrafts::attributes($command->attributes);
        $removed = $set->optionIdsRemovedBy($drafts);
        if ($removed !== [] && $this->products->isAnyOptionUsedByVariations($removed)) {
            throw new CatalogRuleViolation('A product variation uses an option this would remove; change or remove the variation first.', 'orillacart_option_in_use');
        }
        // Off, its options are no longer offered: variations made of them could not be sold, as when it is deleted.
        if ($set->isActive() && !$command->active && $this->products->isSetUsedByVariations($set)) {
            throw new CatalogRuleViolation('Product variations use options of "%s"; change or remove them before turning it off.', 'orillacart_attribute_set_in_use', values: [$set->name()]);
        }
        $texts = $this->texts->check($command->attributes);
        [$attributesBefore, $optionsBefore] = AttributeTexts::ids($set->ownAttributes());
        $set->rename($command->name);
        $set->activate($command->active);
        $set->replaceAttributes($drafts);
        $this->transactions->run(function () use ($set, $texts, $attributesBefore, $optionsBefore): void {
            $this->sets->save($set);
            $this->texts->write($texts, $set->ownAttributes(), $attributesBefore, $optionsBefore);
        });
        $this->packages->changed(AttributePackages::KIND, $id);
        if ($removed !== []) {
            $this->events->dispatch(new CatalogItemsRemoved(optionIds: $removed));
        }
        // What the products using the set offer changed with it.
        foreach ($this->products->idsUsingSet($id) as $productId) {
            $this->events->dispatch(new ProductChanged($productId));
        }

        return AttributeSetView::fromSet($set, $this->texts->of($set->ownAttributes()));
    }

    /**
     * @throws AttributeSetNotFound
     * @throws CatalogRuleViolation when a product's variations use the set's options
     */
    public function delete(int $id): void
    {
        $set = $this->get($id);
        if ($this->products->isSetUsedByVariations($set)) {
            throw new CatalogRuleViolation('Product variations use options of "%s"; change or remove them first.', 'orillacart_attribute_set_in_use', values: [$set->name()]);
        }
        $optionIds = array_merge(...array_map(static fn(Attribute $attribute): array => $attribute->savedOptionIds(), [...$set->ownAttributes()]));
        $users = $this->products->idsUsingSet($id);
        [$attributeIds, $translatedOptions] = AttributeTexts::ids($set->ownAttributes());
        $this->sets->remove($set);
        $this->texts->forget($attributeIds, $translatedOptions);
        $this->packages->removed(AttributePackages::KIND, $id);
        if ($optionIds !== []) {
            $this->events->dispatch(new CatalogItemsRemoved(optionIds: array_values($optionIds)));
        }
        foreach ($users as $productId) {
            $this->events->dispatch(new ProductChanged($productId));
        }
    }

    private function get(int $id): AttributeSet
    {
        return $this->sets->find($id) ?? throw new AttributeSetNotFound($id);
    }
}
