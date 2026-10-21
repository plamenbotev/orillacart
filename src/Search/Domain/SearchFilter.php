<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A filter the shop offers shoppers over attribute options, such as "Size". Attributes belong to one product or
 * one attribute set, so the filter names the attributes it covers ("Size", "Размер"); their options are matched
 * by name. The key names the filter in the shop's addresses: ?f[size][]=m.
 *
 * It shows in every category, or only in those chosen and below them. A category may order its filters its own
 * way; elsewhere they keep the shop's order (position).
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_filters')]
#[ORM\UniqueConstraint(name: 'search_filter_key', columns: ['filter_key'])]
class SearchFilter
{
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_ATTRIBUTES = 20;
    public const int MAX_CATEGORIES = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_LABEL_LENGTH)]
    private string $label;

    #[ORM\Column(length: 40)]
    private string $filterKey;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $attributes;

    #[ORM\Column]
    private int $position;

    /** @var list<int> the categories it shows in, with those below them; none for every one */
    #[ORM\Column(type: 'json')]
    private array $categoryIds = [];

    /** @var array<int, int> its place among a category's filters, by category id */
    #[ORM\Column(type: 'json')]
    private array $categoryPositions = [];

    /**
     * @param list<string> $attributes
     * @param list<int>    $categoryIds
     *
     * @throws SearchRuleViolation
     */
    public function __construct(string $label, string $key, array $attributes, int $position = 0, array $categoryIds = [])
    {
        $this->change($label, $key, $attributes, $position, $categoryIds);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The filter has no id until it is saved.');
    }

    public function label(): string
    {
        return $this->label;
    }

    public function key(): string
    {
        return $this->filterKey;
    }

    /** @return list<string> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** @return list<int> none when it shows in every category */
    public function categoryIds(): array
    {
        return $this->categoryIds;
    }

    /** Whether it shows among the products of a category, given with those above it; or of no category. */
    public function showsIn(?int $categoryId, int ...$above): bool
    {
        if ($this->categoryIds === []) {
            return true;
        }

        return $categoryId !== null && array_intersect($this->categoryIds, [$categoryId, ...$above]) !== [];
    }

    public function positionIn(int $categoryId): ?int
    {
        return $this->categoryPositions[$categoryId] ?? null;
    }

    /** Its place among the category's filters; null for the shop's order there. */
    public function placeIn(int $categoryId, ?int $position): void
    {
        if ($categoryId <= 0) {
            throw new SearchRuleViolation('A filter is ordered in a category that exists.', 'orillacart_invalid_filter_category');
        }
        if ($position === null) {
            unset($this->categoryPositions[$categoryId]);
        } else {
            $this->categoryPositions[$categoryId] = max(0, $position);
        }
    }

    /**
     * @param list<string> $attributes  the attribute names it covers; each once, whatever its case
     * @param list<int>    $categoryIds the categories it shows in, with those below them; none for every one
     *
     * @throws SearchRuleViolation
     */
    public function change(string $label, string $key, array $attributes, int $position, array $categoryIds = []): void
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new SearchRuleViolation(\sprintf('A filter needs a label of up to %d characters.', self::MAX_LABEL_LENGTH), 'orillacart_invalid_filter_label');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $key) !== 1) {
            throw new SearchRuleViolation('A filter key is lowercase letters, digits, dashes and underscores, up to 40: "size".', 'orillacart_invalid_filter_key');
        }
        $names = [];
        foreach ($attributes as $attribute) {
            $attribute = trim((string) preg_replace('/\s+/u', ' ', $attribute));
            if ($attribute !== '') {
                $names[Facets::attribute($attribute)] ??= $attribute;
            }
        }
        if ($names === [] || \count($names) > self::MAX_ATTRIBUTES) {
            throw new SearchRuleViolation(\sprintf('A filter covers 1 to %d attributes.', self::MAX_ATTRIBUTES), 'orillacart_invalid_filter_attributes');
        }
        $categoryIds = array_values(array_unique($categoryIds));
        if (\count($categoryIds) > self::MAX_CATEGORIES || array_filter($categoryIds, static fn(int $id): bool => $id <= 0) !== []) {
            throw new SearchRuleViolation(\sprintf('A filter shows in up to %d categories, or in every one.', self::MAX_CATEGORIES), 'orillacart_invalid_filter_category');
        }
        $this->label = $label;
        $this->filterKey = $key;
        $this->categoryIds = $categoryIds;
        $this->attributes = array_values($names);
        $this->position = max(0, $position);
    }
}
