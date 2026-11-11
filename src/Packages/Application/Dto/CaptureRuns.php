<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use OrillaCart\Packages\Application\Captures;
use Symfony\Component\Validator\Constraints as Assert;

/** Command: record an extension's next runs as the shop makes them, for its debugger (design 71). */
final readonly class CaptureRuns
{
    public function __construct(
        /** Only the handlers whose name starts so: "cart_rule age", "on order_paid"; every one when left out. */
        #[Assert\Length(max: 120)]
        public ?string $what = null,
        /** How many runs to record. */
        #[Assert\Range(min: 1, max: Captures::MOST_RUNS)]
        public int $runs = 5,
        /** For how long, in minutes, at most. */
        #[Assert\Range(min: 1, max: Captures::MOST_MINUTES)]
        public int $minutes = 15,
    ) {}
}
