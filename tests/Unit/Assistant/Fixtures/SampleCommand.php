<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SampleCommand
{
    /**
     * @param list<SampleLine> $lines the items it covers,
     *                                if any
     * @param list<int>        $ids
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,
        #[Assert\Regex('/^\d{1,11}(\.\d{1,4})?$/', message: 'Use an amount such as 25.50.')]
        public string $amount,
        #[Assert\Valid]
        #[Assert\Count(max: 5)]
        public array $lines = [],
        #[Assert\All([new Assert\Positive()])]
        public array $ids = [],
        public ?string $note = null,
        public bool $notify = false,
        public SampleKind $kind = SampleKind::Fixed,
        #[Assert\Choice(choices: ['a', 'b'])]
        public string $letter = 'a',
        #[Assert\Date]
        public ?string $from = null,
        #[Assert\Regex('/^[a-z]+$/i')]
        public string $caseless = '',
    ) {}
}
