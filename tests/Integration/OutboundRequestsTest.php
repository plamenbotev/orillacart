<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Shared\WordPress\OutboundRequests;
use PHPUnit\Framework\TestCase;

/** A request out of the shop to a chosen address: never to the server's own network, never redirected. */
final class OutboundRequestsTest extends TestCase
{
    public function testAPrivateAddressIsRefusedBeforeAnythingConnects(): void
    {
        foreach (['https://169.254.169.254/latest/meta-data/', 'https://127.0.0.1:9200/', 'https://[::ffff:10.0.0.1]/'] as $url) {
            $response = OutboundRequests::send($url, ['timeout' => 1.0]);
            self::assertInstanceOf(\WP_Error::class, $response, $url);
            self::assertSame('orillacart_unsafe_address', $response->get_error_code(), $url);
        }
    }

    public function testNoRedirectIsFollowedAndAnAnswerGivenEarlierStands(): void
    {
        $seen = null;
        $answer = static function (mixed $pre, mixed $args, mixed $url) use (&$seen): array {
            $seen = $args;

            return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $answer, 10, 3);
        try {
            $response = OutboundRequests::send('https://10.0.0.5/hook', ['redirection' => 5]);
        } finally {
            remove_filter('pre_http_request', $answer, 10);
        }

        self::assertIsArray($response, 'what answers the request itself is not second-guessed: nothing connects');
        self::assertIsArray($seen);
        self::assertSame(0, $seen['redirection']);
    }
}
