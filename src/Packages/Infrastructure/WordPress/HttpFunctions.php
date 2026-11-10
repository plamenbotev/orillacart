<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\Host\HourlyQuota;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\WordPress\OutboundRequests;

/**
 * `http`: calls out of the shop, to the hosts the extension declared and a manager granted, over HTTPS, with a
 * short timeout and a cap on the answer; to public addresses only, with no redirect followed (a 3xx is the answer,
 * and its location a host the extension must have declared too). Local names work in development only. A host that does not answer is an `unavailable` failure the script may
 * catch, and the shop's provider handling knows. A call waits at most what the run has left of its time, and an
 * extension makes at most 500 calls an hour across its runs (HourlyQuota).
 */
final readonly class HttpFunctions implements HostNamespace
{
    private const int MOST_SECONDS = 5;
    private const int MOST_BYTES = 1024 * 1024;

    public function __construct(private HourlyQuota $quota) {}

    public function functions(): array
    {
        $functions = [];
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $functions[] = new HostFunction(
                'http.' . $method,
                fn(Context $c, mixed $url, mixed $options = []): array => $this->request($c, strtoupper($method), $url, $options),
                1,
                2,
                \sprintf('http.%s(url, { json?, body?, form?, headers?, timeout? }): a %s to a declared host; answers { status, headers, body, json }', $method, strtoupper($method)),
                'http',
                HostFunction::HTTP,
                writes: true,
            );
        }

        return $functions;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    private function request(Context $c, string $method, mixed $url, mixed $options): array
    {
        $url = Ops::text($url);
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!\is_string($host) || !\in_array($host, $c->grant->hosts, true)) {
            throw new ScriptError(\sprintf('The extension may not call %s: declare the host in needs.http and enable it again.', \is_string($host) ? $host : $url));
        }
        if ($scheme !== 'https' && !(OutboundRequests::local(strtolower($host)) && OutboundRequests::development())) {
            throw new ScriptError('Calls out of the shop go over https.');
        }
        $options = \is_array($options) ? $options : [];
        $headers = [];
        foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
            $headers[(string) $name] = Ops::text($value);
        }
        $body = null;
        if (\array_key_exists('json', $options)) {
            $body = (string) json_encode(Ops::plain($options['json']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] ??= 'application/json';
        } elseif (\is_array($options['form'] ?? null)) {
            $body = http_build_query(array_map(Ops::text(...), $options['form']));
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        } elseif (isset($options['body'])) {
            $body = Ops::text($options['body']);
        }
        $timeout = isset($options['timeout']) ? min(self::MOST_SECONDS, max(1, (int) Ops::text($options['timeout']))) : self::MOST_SECONDS;
        // A call waits no longer than the run has left.
        $timeout = min((float) $timeout, $c->secondsLeft());
        $this->quota->take($c, HourlyQuota::HTTP, 'HTTP calls');

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'limit_response_size' => self::MOST_BYTES,
            'user-agent' => 'OrillaCart extension ' . $c->grant->extension,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $response = OutboundRequests::send($url, $args, allowLocal: true);
        if (is_wp_error($response)) {
            throw new ScriptFailure(ScriptFailure::UNAVAILABLE, \sprintf('%s did not answer: %s', $host, $response->get_error_message()));
        }
        $text = wp_remote_retrieve_body($response);
        $json = json_decode($text, true);
        $answered = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $answered[strtolower((string) $name)] = \is_array($value) ? implode(', ', array_map(static fn(mixed $part): string => \is_scalar($part) ? (string) $part : '', $value)) : (\is_scalar($value) ? (string) $value : '');
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'headers' => $answered, 'body' => $text, 'json' => \is_array($json) || \is_scalar($json) ? $json : null];
    }
}
