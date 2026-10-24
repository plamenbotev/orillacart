<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http;

use OrillaCart\Shared\Domain\Text;

/**
 * Stops a request with a status and a message that is safe to show to the caller.
 */
final class HttpException extends \RuntimeException
{
    /** The message, to translate where it is shown. */
    public readonly Text $text;

    /**
     * @param array<string, mixed> $details extra data for the response body
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string|Text $message,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        $this->text = $message instanceof Text ? $message : new Text($message);
        parent::__construct((string) $this->text, 0, $previous);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'orillacart_unauthorized', 'You need to log in to do this.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'orillacart_forbidden', 'You are not allowed to do this.');
    }

    public static function invalidNonce(): self
    {
        return new self(403, 'orillacart_invalid_nonce', 'The link you followed has expired. Reload the page and try again.');
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'orillacart_not_found', $message);
    }

    /**
     * @param list<array{field: string, message: string|Text}> $violations
     */
    public static function invalidInput(array $violations): self
    {
        return new self(422, 'orillacart_invalid_input', 'Some fields are not valid.', ['violations' => $violations]);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'orillacart_bad_request', $message);
    }
}
