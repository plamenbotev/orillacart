<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Infrastructure\WordPress;

use OrillaCart\Audit\Application\ShopProblems;
use OrillaCart\Shared\WordPress\ScheduledTask;

/** Daily: forgets the problems last seen more than a month ago. */
final readonly class ForgetOldProblems implements ScheduledTask
{
    public function __construct(private ShopProblems $problems) {}

    public static function hook(): string
    {
        return 'orillacart_forget_problems';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->problems->forgetOld();
    }
}
