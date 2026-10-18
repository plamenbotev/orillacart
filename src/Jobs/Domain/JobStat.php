<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * What one type of job did in one hour: the history the overview charts. Done jobs are not kept one by one: at
 * thousands an hour, counting them is what scales. Workers add their batch's counts in one statement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'job_stats')]
class JobStat
{
    #[ORM\Column]
    private int $done = 0;

    #[ORM\Column]
    private int $retried = 0;

    #[ORM\Column]
    private int $failed = 0;

    /** Milliseconds spent on the done ones, for the average. */
    #[ORM\Column(type: 'bigint')]
    private int $runtimeMs = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private \DateTimeImmutable $hour,
        #[ORM\Id]
        #[ORM\Column(length: Job::MAX_TYPE_LENGTH)]
        private string $type,
    ) {}

    public function hour(): \DateTimeImmutable
    {
        return $this->hour;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function done(): int
    {
        return $this->done;
    }

    public function retried(): int
    {
        return $this->retried;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function runtimeMs(): int
    {
        return $this->runtimeMs;
    }
}
