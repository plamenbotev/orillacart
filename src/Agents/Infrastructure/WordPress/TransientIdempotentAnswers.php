<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\IdempotentAnswers;

/** A day of answers by key, in transients: the object cache where the site has one. */
final readonly class TransientIdempotentAnswers implements IdempotentAnswers
{
    public function get(string $key): ?array
    {
        $known = get_transient(self::name($key));
        if (!\is_array($known) || !\is_string($known['body'] ?? null) || !\is_array($known['answer'] ?? null) || !\is_int($known['answer']['status'] ?? null) || !\is_array($known['answer']['body'] ?? null)) {
            return null;
        }
        $body = [];
        foreach ($known['answer']['body'] as $name => $value) {
            $body[(string) $name] = $value;
        }

        return ['body' => $known['body'], 'answer' => ['status' => $known['answer']['status'], 'body' => $body]];
    }

    public function keep(string $key, string $body, array $answer): void
    {
        set_transient(self::name($key), ['body' => $body, 'answer' => $answer], DAY_IN_SECONDS);
    }

    private static function name(string $key): string
    {
        return 'orillacart_ucp_key_' . md5($key);
    }
}
