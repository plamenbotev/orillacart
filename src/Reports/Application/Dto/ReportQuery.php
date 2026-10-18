<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application\Dto;

use OrillaCart\Reports\Domain\Interval;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a report over days of the shop's calendar, the last 30 days by default.
 */
final readonly class ReportQuery
{
    public const string PREVIOUS = 'previous';
    public const string YEAR = 'year';
    public const string NONE = 'none';

    public function __construct(
        /** The first day, Y-m-d. */
        #[Assert\Date]
        public ?string $from = null,
        /** The last day, Y-m-d, included. */
        #[Assert\Date]
        public ?string $to = null,
        /** How the chart groups days: day, week or month; by default what suits the period's length. */
        #[Assert\Choice(callback: [Interval::class, 'values'])]
        public ?string $interval = null,
        /** What to compare with: the days just before, the same days a year before, or nothing. */
        #[Assert\Choice(choices: [self::PREVIOUS, self::YEAR, self::NONE])]
        public string $compare = self::PREVIOUS,
        /** How many rows a ranking lists. */
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 10,
        /** For products: by what they brought (net), or how many were sold (quantity). */
        #[Assert\Choice(choices: ['net', 'quantity'])]
        public string $sort = 'net',
    ) {}
}
