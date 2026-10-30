<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Output;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Turns result and view DTOs into JSON responses. Entities never reach it: actions pass what a use case returned.
 */
final readonly class JsonResponder
{
    /**
     * A header that asks the REST bridge to keep the answer's JSON objects as objects, so that {} stays {} where a
     * protocol needs it (MCP). The bridge takes it off before the answer leaves.
     */
    public const string KEEP_OBJECTS = 'X-OrillaCart-Keep-Objects';

    public function __construct(
        private NormalizerInterface $normalizer,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * @param object|list<object>|array<string, mixed>|null $data
     * @param list<string>                                  $groups serialization groups; empty means every property
     */
    public function ok(object|array|null $data, int $status = 200, array $groups = []): ResponseInterface
    {
        $context = $groups === [] ? [] : [AbstractNormalizer::GROUPS => $groups];

        return $this->json($this->normalizer->normalize($data, 'json', $context), $status);
    }

    /**
     * A view as the answer's JSON holds it, for an error's details: the same normalizing ok() does.
     *
     * @param object|list<object>|array<string, mixed> $data
     */
    public function normalized(object|array $data): mixed
    {
        return $this->normalizer->normalize($data, 'json');
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204);
    }

    /**
     * The error shape WordPress uses for REST errors, so admin screens handle both alike.
     *
     * @param array<string, mixed> $details
     */
    public function error(int $status, string $code, string $message, array $details = []): ResponseInterface
    {
        return $this->json(['code' => $code, 'message' => $message, 'data' => ['status' => $status] + $details], $status);
    }

    /**
     * JSON written as given, without normalizing: an empty stdClass stays {}.
     */
    public function exact(mixed $payload, int $status = 200): ResponseInterface
    {
        return $this->json($payload, $status)->withHeader(self::KEEP_OBJECTS, '1');
    }

    private function json(mixed $payload, int $status): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream($body));
    }
}
