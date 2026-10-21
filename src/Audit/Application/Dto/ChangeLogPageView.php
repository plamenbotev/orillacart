<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application\Dto;

final readonly class ChangeLogPageView
{
    /**
     * @param list<ChangeRecordView> $records newest first
     * @param list<string>           $areas   every area changed, to filter by
     */
    public function __construct(
        public array $records,
        public int $total,
        public int $page,
        public int $pages,
        public array $areas,
    ) {}
}
