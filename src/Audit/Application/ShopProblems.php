<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application;

use OrillaCart\Audit\Application\Dto\ProblemPage;
use OrillaCart\Audit\Application\Dto\ProblemView;
use OrillaCart\Audit\Domain\LoggedProblemRepository;
use Psr\Clock\ClockInterface;

/**
 * What went wrong that the shop should hear of, as the Status screen and Site Health show it: payments and
 * refunds the shop could not record, emails that did not go, couriers and add-ons that failed, settings that
 * could not be read. Kept a month.
 */
final readonly class ShopProblems
{
    public const int PER_PAGE = 50;
    private const string KEPT = 'P30D';

    public function __construct(
        private LoggedProblemRepository $problems,
        private ClockInterface $clock,
    ) {}

    public function page(int $page = 1): ProblemPage
    {
        $page = max(1, $page);

        return new ProblemPage(array_map(ProblemView::of(...), $this->problems->page(($page - 1) * self::PER_PAGE, self::PER_PAGE)), $this->problems->total(), $page, self::PER_PAGE);
    }

    /** How many problems the shop saw in the last day. */
    public function lately(): int
    {
        return $this->problems->seenSince($this->clock->now()->sub(new \DateInterval('P1D')));
    }

    /** The shop looked at them: the list starts again. */
    public function clear(): void
    {
        $this->problems->clear();
    }

    /** Daily: forgets what was last seen more than a month ago. */
    public function forgetOld(): void
    {
        $this->problems->forgetBefore($this->clock->now()->sub(new \DateInterval(self::KEPT)));
    }
}
