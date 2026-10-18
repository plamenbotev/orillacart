<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Files;

use OrillaCart\Shared\WordPress\AsHook;

/**
 * Answers the site's own test request (?orillacart_delivery_probe=TOKEN) with only the hand-off header and no
 * body: the test file arrives only when the web server acts on the header.
 */
#[AsHook('init', priority: 0)]
final readonly class DeliveryProbe
{
    public function __construct(private WebServerFileDelivery $delivery) {}

    public function __invoke(): void
    {
        $token = $_GET[WebServerFileDelivery::PROBE_QUERY] ?? null;
        if (!\is_string($token)) {
            return;
        }
        $header = $this->delivery->probe($token);
        if ($header === null) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: text/plain');
        header($header);
        exit;
    }
}
