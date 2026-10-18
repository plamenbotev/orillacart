<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * The search engine as the settings screen shows it, with how far behind the index is and what the last change
 * found. The password never leaves the server; password_set says whether one is saved.
 */
final readonly class SearchEngineView
{
    /**
     * @param bool                      $ready  whether OpenSearch answers shoppers: its index was built
     * @param array<int, string>        $parked products that failed too often, with the last error
     * @param bool|null                 $works  what the last change found; null when nothing was changed
     */
    public function __construct(
        public string $engine,
        public string $host,
        public string $username,
        public bool $passwordSet,
        public string $index,
        public bool $ready,
        public int $due,
        public int $later,
        public array $parked,
        public ?string $nextAt,
        public ?bool $works = null,
        public string $message = '',
        /** Workers that run all the time (wp orillacart jobs work --loop), seen lately. */
        public int $workers = 0,
    ) {}
}
