<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\Site;

final readonly class WordPressSite implements Site
{
    public function name(): string
    {
        return wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    public function adminEmail(): string
    {
        return get_bloginfo('admin_email');
    }

    public function url(): string
    {
        return home_url('/');
    }
}
