<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Rest;

use OrillaCart\Shared\Http\HttpKernel;
use OrillaCart\Shared\Http\Routing\RouteDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Carries a REST request into the HTTP kernel as a PSR-7 request and the kernel's response back to WordPress.
 */
final readonly class RestBridge
{
    public function __construct(
        private HttpKernel $kernel,
        private ServerRequestFactoryInterface $requests,
        private UriFactoryInterface $uris,
        private StreamFactoryInterface $streams,
        private UploadedFileFactoryInterface $uploads,
    ) {}

    public function handle(RouteDefinition $route, \WP_REST_Request $request): \WP_REST_Response
    {
        return $this->toWordPress($this->kernel->handle($this->toPsr($route, $request)));
    }

    public function toPsr(RouteDefinition $route, \WP_REST_Request $request): ServerRequestInterface
    {
        $query = $request->get_query_params();
        // get_body() is documented as a string but returns null when the request has no body.
        $body = $request->get_body();
        $body = \is_string($body) ? $body : '';
        $uri = $this->uris->createUri(rest_url(ltrim($request->get_route(), '/')))
            ->withQuery(http_build_query($query));

        $psr = $this->requests->createServerRequest($request->get_method(), $uri, $_SERVER)
            ->withQueryParams($query)
            ->withCookieParams($_COOKIE)
            ->withBody($this->streams->createStream($body))
            ->withParsedBody($request->get_json_params() ?? $request->get_body_params())
            ->withAttribute(RouteDefinition::ATTRIBUTE, $route);

        $files = [];
        foreach ($request->get_file_params() as $name => $file) {
            if (\is_array($file) && \is_string($file['tmp_name'] ?? null) && \is_int($file['error'] ?? null)) {
                $files[(string) $name] = $this->uploads->createUploadedFile(
                    $this->streams->createStreamFromFile($file['tmp_name'] === '' ? 'php://memory' : $file['tmp_name']),
                    \is_int($file['size'] ?? null) ? $file['size'] : null,
                    $file['error'],
                    \is_string($file['name'] ?? null) ? $file['name'] : null,
                    \is_string($file['type'] ?? null) ? $file['type'] : null,
                );
            }
        }
        $psr = $psr->withUploadedFiles($files);

        // WordPress stores header names lower-cased with underscores: x_wp_nonce
        foreach ($request->get_headers() as $name => $values) {
            $values = array_values(array_filter(\is_array($values) ? $values : [$values], \is_string(...)));
            $psr = $psr->withHeader(str_replace('_', '-', (string) $name), $values);
        }
        foreach ($request->get_url_params() as $name => $value) {
            $psr = $psr->withAttribute((string) $name, $value);
        }

        return $psr;
    }

    public function toWordPress(ResponseInterface $response): \WP_REST_Response
    {
        $body = (string) $response->getBody();
        $data = $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new \WP_REST_Response($data, $response->getStatusCode(), $headers);
    }
}
