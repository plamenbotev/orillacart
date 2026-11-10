<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\RedirectTargets;

/** The site's own addresses as WordPress validates a redirect: a path, the home host, or an `allowed_redirect_hosts` host. */
final readonly class WordPressRedirectTargets implements RedirectTargets
{
    public function sameSite(string $url): ?string
    {
        $safe = $url === '' ? '' : wp_validate_redirect(wp_sanitize_redirect($url), '');

        return $safe === '' ? null : $safe;
    }
}
