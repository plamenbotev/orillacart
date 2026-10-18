<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A filter the shop offers shoppers over attribute options, such as "Size". Attributes belong to one product or
 * one attribute set, so the filter names the attributes it covers ("Size", "Размер"); their options are matched
 * by name. The key names the filter in the shop's addresses: ?f[size][]=m.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_filters')]
#[ORM\UniqueConstraint(name: 'search_filter_key', columns: ['filter_key'])]
class SearchFilter
{
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_ATTRIBUTES = 20;

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

    /**
     * @param list<string> $attributes
     *
     * @throws SearchRuleViolation
     */
    public function __construct(string $label, string $key, array $attributes, int $position = 0)
    {
        $this->change($label, $key, $attributes, $position);
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

    /**
     * @param list<string> $attributes the attribute names it covers; each once, whatever its case
     *
     * @throws SearchRuleViolation
     */
    public function change(string $label, string $key, array $attributes, int $position): void
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
        $this->label = $label;
        $this->filterKey = $key;
        $this->attributes = array_values($names);
        $this->position = max(0, $position);
    }
}
