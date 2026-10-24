<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something that went wrong which the shop should hear of (the shop's warnings and errors): the same message once,
 * counted, with when it was first and last seen. The shop's logger writes them (ShopLogger); kept a month.
 */
#[ORM\Entity]
#[ORM\Table(name: 'problems')]
#[ORM\UniqueConstraint(name: 'problems_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'problems_last', columns: ['last_at'])]
class LoggedProblem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** The level and message hashed, so the same problem is one row. */
        #[ORM\Column(length: 40, options: ['fixed' => true])]
        private string $fingerprint,
        #[ORM\Column(length: 10)]
        private string $level,
        #[ORM\Column(type: 'text')]
        private string $message,
        #[ORM\Column]
        private int $count,
        #[ORM\Column]
        private \DateTimeImmutable $firstAt,
        #[ORM\Column]
        private \DateTimeImmutable $lastAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The problem was not saved.');
    }

    public function level(): string
    {
        return $this->level;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function firstAt(): \DateTimeImmutable
    {
        return $this->firstAt;
    }

    public function lastAt(): \DateTimeImmutable
    {
        return $this->lastAt;
    }
}
