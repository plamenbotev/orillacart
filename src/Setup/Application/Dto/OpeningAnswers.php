<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

/**
 * Whether visitors see "Opening soon" while the shop is set up.
 */
final readonly class OpeningAnswers
{
    public function __construct(public bool $opensSoon = true) {}
}
