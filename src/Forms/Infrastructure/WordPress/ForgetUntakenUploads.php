<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: deletes the files shoppers sent that no order took, once they are old.
 */
final readonly class ForgetUntakenUploads implements ScheduledTask
{
    public function __construct(private FormUploads $uploads) {}

    public static function hook(): string
    {
        return 'orillacart_forget_untaken_uploads';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        while ($this->uploads->forgetUntaken() > 0);
    }
}
