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

    public function testTheAddressIsWrittenInLowercaseBeforeTheGuardReadsIt(): void
    {
        $seen = null;
        $answer = static function (mixed $pre, mixed $args, mixed $url) use (&$seen): array {
            $seen = $url;

            return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $answer, 10, 3);
        try {
            OutboundRequests::send('HTTP://Hooks.Example.COM/in#part', []);
        } finally {
            remove_filter('pre_http_request', $answer, 10);
        }

        self::assertSame('http://hooks.example.com/in', $seen);
        $refused = OutboundRequests::send('gopher://example.com/', []);
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame('orillacart_unsafe_address', $refused->get_error_code());
    }

    public function testARequestWhoseGuardAFilterTookAwayIsRefused(): void
    {
        $strip = static function (array $args): array {
            unset($args[OutboundRequests::PUBLIC_ONLY]);

            return $args;
        };
        add_filter('http_request_args', $strip, 10, 1);
        try {
            $response = OutboundRequests::send('https://127.0.0.1:9200/', ['timeout' => 1.0]);
        } finally {
            remove_filter('http_request_args', $strip, 10);
        }

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('orillacart_unsafe_address', $response->get_error_code());
        self::assertFalse(OutboundRequests::sending('https://127.0.0.1:9200/'), 'no longer under way');
    }

    public function testARequestWhoseGuardAFilterTurnedOffIsRefused(): void
    {
        $off = static function (array $args): array {
            $args[OutboundRequests::PUBLIC_ONLY] = false;

            return $args;
        };
        add_filter('http_request_args', $off, 10, 1);
        try {
            $response = OutboundRequests::send('https://127.0.0.1:9200/', ['timeout' => 1.0]);
        } finally {
            remove_filter('http_request_args', $off, 10);
        }

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame('orillacart_unsafe_address', $response->get_error_code());
        self::assertNull(OutboundRequests::expected('https://127.0.0.1:9200/'), 'no longer under way');
    }

    public function testAHostWrittenInEscapesNeverReachesWordPress(): void
    {
        $asked = [];
        $watch = static function (mixed $pre, mixed $args, mixed $url) use (&$asked): mixed {
            $asked[] = $url;

            return $pre;
        };
        add_filter('pre_http_request', $watch, 1, 3);
        try {
            foreach (['http://a%2Db.example.com/', 'http://%31%32%37.0.0.1/', 'http://2130706433/', "http://\u{FF11}27.0.0.1/"] as $url) {
                $response = OutboundRequests::send($url, ['timeout' => 1.0]);
                self::assertInstanceOf(\WP_Error::class, $response, $url);
                self::assertSame('orillacart_unsafe_address', $response->get_error_code(), $url);
            }
        } finally {
            remove_filter('pre_http_request', $watch, 1);
        }

        self::assertSame([], $asked);
    }

    public function testCurlGivenAnotherHostThanTheOneCheckedConnectsNowhere(): void
    {
        $args = [OutboundRequests::PUBLIC_ONLY => true];
        $url = 'http://93.184.215.14/hook';
        // The two steps of the guard as WordPress runs them, with what a library in between does to the host.
        $given = static function (string $to) use ($args, $url): string {
            self::assertFalse(apply_filters('pre_http_request', false, $args, $url), 'checked, and left to connect');
            $handle = curl_init($to);
            self::assertInstanceOf(\CurlHandle::class, $handle);
            do_action_ref_array('http_api_curl', [&$handle, $args, $url]);

            return (string) curl_getinfo($handle, \CURLINFO_EFFECTIVE_URL);
        };

        self::assertSame('http://93.184.215.14/hook', $given('http://93.184.215.14/hook'), 'the host that was checked: pinned and sent');
        self::assertSame('http://0.0.0.0:0/', $given('http://other.example.org/hook'), 'another host: looked up by no one, so nowhere');
        self::assertSame('http://0.0.0.0:0/', $given('http://93.184.215.14.example.org/hook'));
    }
}
