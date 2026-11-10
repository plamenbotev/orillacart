<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A change someone of the shop made through the shop's API: who, when, which route, and what they sent, with
 * secrets blanked out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'change_log')]
#[ORM\Index(name: 'change_log_when', columns: ['made_at'])]
#[ORM\Index(name: 'change_log_area', columns: ['area', 'made_at'])]
class ChangeRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param string               $area   what was changed, as the route's first part: "payment-methods"
     * @param string               $route  the route, its parameters named: "PUT /payment-methods/{gateway}"
     * @param string               $path   the route as called: "/payment-methods/paypal"
     * @param array<string, mixed> $values what was sent, secrets blanked out
     */
    public function __construct(
        #[ORM\Column(nullable: true)]
        private ?int $userId,
        #[ORM\Column(length: 100)]
        private string $userName,
        #[ORM\Column(length: 64)]
        private string $area,
        #[ORM\Column(length: 200)]
        private string $route,
        #[ORM\Column(length: 255)]
        private string $path,
        #[ORM\Column(name: 'sent_values', type: 'json')]
        private array $values,
        #[ORM\Column]
        private \DateTimeImmutable $madeAt,
        /** How the change came: "assistant" (the AI assistant's tools) or "api_key"; null from the admin's screens. */
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $via = null,
        /** The API key it came with, by name. */
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $keyName = null,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The record has no id until it is saved.');
    }

    public function via(): ?string
    {
        return $this->via;
    }

    public function keyName(): ?string
    {
        return $this->keyName;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function userName(): string
    {
        return $this->userName;
    }

    public function area(): string
    {
        return $this->area;
    }

    public function route(): string
    {
        return $this->route;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function madeAt(): \DateTimeImmutable
    {
        return $this->madeAt;
    }

    /**
     * Whether what was sent names the email, in any case: as a value of its own or within a longer one ("write to
     * ada@example.com"), but whole: "n@gmail.com" is not in "john@gmail.com", nor "ada@example.co" in
     * "ada@example.com".
     *
     * @param array<array-key, mixed> $values
     */
    public static function mentions(array $values, string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }
        // What may stand next to an address without being part of it: anything but the characters it is made of.
        $pattern = '/(?<![\p{L}\p{N}._%+\-])' . preg_quote($email, '/') . '(?![\p{L}\p{N}_%+\-]|\.[\p{L}\p{N}])/iu';
        $found = false;
        array_walk_recursive($values, static function (mixed $value, int|string $key) use ($pattern, &$found): void {
            $found = $found || (\is_string($value) && preg_match($pattern, $value) === 1) || (\is_string($key) && preg_match($pattern, $key) === 1);
        });

        return $found;
    }
}
