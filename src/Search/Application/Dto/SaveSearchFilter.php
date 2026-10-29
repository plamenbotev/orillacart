<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

use OrillaCart\Search\Domain\SearchFilter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: a filter shoppers see in the shop, over the attributes with these names.
 */
final readonly class SaveSearchFilter
{
    /**
     * @param list<string> $attributes attribute names, such as "Size" and "Размер"
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: SearchFilter::MAX_LABEL_LENGTH)]
        public string $label,
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_-]{0,39}$/')]
        public string $key,
        #[Assert\Count(min: 1, max: SearchFilter::MAX_ATTRIBUTES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 100)])]
        public array $attributes,
        #[Assert\PositiveOrZero]
        public int $position = 0,
        /**
         * The label in the site's other languages, by locale; null leaves them as they are.
         *
         * @var array<string, string>|null
         */
        #[Assert\All([new Assert\Type('string')])]
        public ?array $translations = null,
    ) {}
}
