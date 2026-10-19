<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An account asked for and not made yet: the visitor gets a link by email, and the account is made, with the
 * password they choose there, only once they use it. So signing up proves the email is theirs, and says nothing
 * about whether it has an account already. Only the link's hash is kept.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pending_accounts')]
#[ORM\UniqueConstraint(name: 'pending_account_token', columns: ['token_hash'])]
#[ORM\Index(name: 'pending_account_email', columns: ['email'])]
class PendingAccount
{
    /** How long the link works. */
    public const string VALID_FOR = '+48 hours';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param list<int> $orderIds the orders placed while asking for it, to give the account
     */
    private function __construct(
        #[ORM\Column(length: 200)]
        private string $email,
        #[ORM\Column(length: 200)]
        private string $firstName,
        #[ORM\Column(length: 200)]
        private string $lastName,
        #[ORM\Column(type: 'json')]
        private array $orderIds,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        string $token,
    ) {
        $this->tokenHash = self::hash($token);
        $this->expiresAt = $createdAt->modify(self::VALID_FOR);
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array{self, string} the pending account and its link's token, which is not kept
     */
    public static function start(string $email, string $firstName, string $lastName, array $orderIds, \DateTimeImmutable $now): array
    {
        $token = bin2hex(random_bytes(32));

        return [new self(mb_strtolower(trim($email)), trim($firstName), trim($lastName), array_values(array_unique($orderIds)), $now, $token), $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    /** @return list<int> */
    public function orderIds(): array
    {
        return array_values(array_map(intval(...), $this->orderIds));
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
