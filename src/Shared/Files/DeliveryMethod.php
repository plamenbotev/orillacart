<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * Who sends a private file to the browser: PHP reads it, or the web server does once PHP has checked the link.
 */
enum DeliveryMethod: string
{
    /** PHP reads the file; works everywhere, but keeps a PHP worker busy for the whole download. */
    case Php = 'php';
    /** Apache with mod_xsendfile, LiteSpeed and Lighttpd. */
    case XSendfile = 'x_sendfile';
    /** Nginx, through an internal location. */
    case XAccelRedirect = 'x_accel_redirect';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $method): string => $method->value, self::cases());
    }
}
