<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Every day: deletes the files shoppers sent that no order took, once they are old, and the temporary copies a
 * request that failed on the way left behind.
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
        $left = glob(sys_get_temp_dir() . '/' . FormUploads::TEMPORARY_PREFIX . '*');
        foreach ($left === false ? [] : $left as $file) {
            if (is_file($file) && filemtime($file) < time() - HOUR_IN_SECONDS) {
                wp_delete_file($file);
            }
        }
    }
}
