<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Integrations\Application\ApiKeys;
use Psr\Clock\ClockInterface;

/**
 * What the owner chose, per API key, for the AI assistant that uses it, and the tools they let it run unasked for
 * a while with a yes on the shop's page. Those are kept with the choices, as tool => until (a Unix time), and go
 * with the key.
 */
final readonly class AssistantKeys
{
    /** Where in the key's stored choices the tools let run unasked are. */
    public const string ALLOWED = 'allowed';

    /** At most this many tools are let run unasked at once; the soonest to run out make room. */
    private const int MOST_ALLOWED = 50;

    public function __construct(private ApiKeys $keys, private ClockInterface $clock) {}

    /** The choices for a key the user may manage: their own, or any for an administrator. */
    public function choices(int $keyId, int $userId, bool $administrator): AssistantChoices
    {
        return AssistantChoices::fromArray($this->keys->assistant($keyId, $userId, $administrator));
    }

    /** Chooses for a key the user may manage: their own, or any for an administrator. The tools let run unasked stay. */
    public function choose(int $keyId, AssistantChoices $choices, int $userId, bool $administrator): AssistantChoices
    {
        $allowed = self::allowed($this->keys->assistant($keyId, $userId, $administrator), $this->now());
        $this->keys->chooseForAssistant($keyId, $choices->toArray() + [self::ALLOWED => $allowed], $userId, $administrator);

        return $this->choices($keyId, $userId, $administrator);
    }

    /**
     * Lets the key run the tool unasked until the time given: the owner's yes on the shop's page, logged in as the
     * key's own user.
     */
    public function allow(int $keyId, int $userId, string $tool, int $until): void
    {
        $stored = $this->keys->assistant($keyId, $userId, false) ?? [];
        $allowed = self::allowed($stored, $this->now());
        $allowed[$tool] = $until;
        arsort($allowed);
        $stored[self::ALLOWED] = \array_slice($allowed, 0, self::MOST_ALLOWED, true);
        $this->keys->chooseForAssistant($keyId, $stored, $userId, false);
    }

    /**
     * The tools a key the user may manage runs unasked, with when each runs out.
     *
     * @return array<string, int> tool => until, the soonest first
     */
    public function allowances(int $keyId, int $userId, bool $administrator): array
    {
        $allowed = self::allowed($this->keys->assistant($keyId, $userId, $administrator), $this->now());
        asort($allowed);

        return $allowed;
    }

    /**
     * The key asks again before the tool, or before every tool when none is named.
     *
     * @return array<string, int> what it still runs unasked
     */
    public function ask(int $keyId, ?string $tool, int $userId, bool $administrator): array
    {
        $stored = $this->keys->assistant($keyId, $userId, $administrator) ?? [];
        $allowed = $tool === null ? [] : array_diff_key(self::allowed($stored, $this->now()), [$tool => true]);
        $stored[self::ALLOWED] = $allowed;
        $this->keys->chooseForAssistant($keyId, $stored, $userId, $administrator);

        return $this->allowances($keyId, $userId, $administrator);
    }

    /**
     * The tools let run unasked in the key's stored choices, those run out left out.
     *
     * @param array<array-key, mixed>|null $stored
     *
     * @return array<string, int> tool => until
     */
    public static function allowed(?array $stored, int $now): array
    {
        $allowed = [];
        foreach (\is_array($stored[self::ALLOWED] ?? null) ? $stored[self::ALLOWED] : [] as $tool => $until) {
            if (\is_string($tool) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $tool) === 1 && \is_int($until) && $until > $now) {
                $allowed[$tool] = $until;
            }
        }

        return $allowed;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
