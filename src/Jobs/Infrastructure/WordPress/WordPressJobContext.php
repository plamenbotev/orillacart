<?php

declare(strict_types=1);

namespace OrillaCart\Jobs\Infrastructure\WordPress;

use OrillaCart\Jobs\Application\JobContext;
use OrillaCart\Shared\Lifecycle\Languages;

/**
 * Runs jobs as no user, in the site's language, and gives the request back its user afterwards.
 */
final readonly class WordPressJobContext implements JobContext
{
    public function __construct(private Languages $languages) {}

    public function run(\Closure $work): mixed
    {
        $user = get_current_user_id();
        wp_set_current_user(0);
        try {
            return $this->languages->in(null, $work);
        } finally {
            wp_set_current_user($user);
        }
    }
}
