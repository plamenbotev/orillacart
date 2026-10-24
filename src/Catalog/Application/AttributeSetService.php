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
    ) {}

    /**
     * @return list<AttributeSetView> by name
     */
    public function sets(): array
    {
        return array_map(AttributeSetView::fromSet(...), $this->sets->all());
    }

    /**
     * @throws AttributeSetNotFound
     */
    public function set(int $id): AttributeSetView
    {
        return AttributeSetView::fromSet($this->get($id));
    }

    public function create(SaveAttributeSet $command): AttributeSetView
    {
        $set = new AttributeSet($command->name, $command->active);
        $set->replaceAttributes(CatalogDrafts::attributes($command->attributes));
        $this->sets->save($set);

        return AttributeSetView::fromSet($set);
    }

    /**
     * @throws AttributeSetNotFound
     * @throws CatalogRuleViolation when a variation uses an option this would remove
     */
    public function update(int $id, SaveAttributeSet $command): AttributeSetView
    {
        $set = $this->get($id);
        $drafts = CatalogDrafts::attributes($command->attributes);
        $removed = $set->optionIdsRemovedBy($drafts);
        if ($removed !== [] && $this->products->isAnyOptionUsedByVariations($removed)) {
            throw new CatalogRuleViolation('A product variation uses an option this would remove; change or remove the variation first.', 'orillacart_option_in_use');
        }
        $set->rename($command->name);
        $set->activate($command->active);
        $set->replaceAttributes($drafts);
        $this->sets->save($set);
        if ($removed !== []) {
            $this->events->dispatch(new CatalogItemsRemoved(optionIds: $removed));
        }
        // What the products using the set offer changed with it.
        foreach ($this->products->idsUsingSet($id) as $productId) {
            $this->events->dispatch(new ProductChanged($productId));
        }

        return AttributeSetView::fromSet($set);
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
        $this->sets->remove($set);
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
