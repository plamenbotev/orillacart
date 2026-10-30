<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * A small option read on every storefront request, kept in WordPress's autoloaded options: stored with its
 * defaults at activation and upgrade when it is missing, as a missing option costs a query per request on a site
 * without an object cache, and switched to autoload when an earlier build saved it without.
 */
final class AutoloadedOption
{
    /**
     * @param string          $option the option's name
     * @param \Closure(): void $store  stores the option's current (default) value, autoloaded
     */
    public static function keep(string $option, \Closure $store): void
    {
        if (get_option($option, null) === null) {
            $store();
        }
        wp_set_option_autoload($option, true);
    }
}
