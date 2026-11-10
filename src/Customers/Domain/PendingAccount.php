<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An account asked for and not made yet: the visitor gets a link by email, and the account is made, with the
 * password they choose there, only once they use it. So signing up proves the email is theirs, and says nothing
 * about whether it has an account already. Only the link's hash is kept.
 *
 * On a network the shop's staff invite a customer the same way, whoever has the email: the invitation keeps who
 * sent it and the groups the account joins once made, and lasts longer than a visitor's own sign-up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pending_accounts')]
#[ORM\UniqueConstraint(name: 'pending_account_token', columns: ['token_hash'])]
#[ORM\Index(name: 'pending_account_email', columns: ['email'])]
class PendingAccount
{
    /** How long the link works. */
    public const string VALID_FOR = '+48 hours';
    /** How long the link of an invitation works: the customer may read their email days after the call. */
    public const string INVITATION_VALID_FOR = '+7 days';
    /** An invitation is sent again at most once a day, or once its link stopped working. */
    public const string RESEND_AFTER = '+1 day';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** @var list<int>|null the customer groups the account joins once made */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $groupIds = null;

    /** The shop's user who invited the customer; null for a visitor's own sign-up. */
    #[ORM\Column(nullable: true)]
    private ?int $invitedBy = null;

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
        #[\SensitiveParameter]
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

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('A pending account has no id before it is saved.');
    }

    /**
     * The shop invites the customer: the account joins the groups once made, and the link lasts as invitations
     * do. A visitor's sign-up for the same email becomes the invitation, its link as it is.
     *
     * @param list<int> $groupIds
     */
    public function invitedBy(int $userId, array $groupIds): void
    {
        $this->invitedBy = $userId;
        $this->groupIds = array_values(array_unique(array_map(intval(...), $groupIds)));
        $this->expiresAt = max($this->expiresAt, $this->createdAt->modify(self::INVITATION_VALID_FOR));
    }

    /** The shop's user who invited the customer; null for a visitor's own sign-up. */
    public function inviter(): ?int
    {
        return $this->invitedBy;
    }

    public function isInvitation(): bool
    {
        return $this->invitedBy !== null;
    }

    /** @return list<int> the customer groups the account joins once made */
    public function groupIds(): array
    {
        return array_values(array_map(intval(...), $this->groupIds ?? []));
    }

    /** When the link was sent. */
    public function sentAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Whether a new link may go: the last stopped working, or went a day ago. */
    public function mayResend(\DateTimeImmutable $now): bool
    {
        return $this->isExpired($now) || $now >= $this->createdAt->modify(self::RESEND_AFTER);
    }

    /**
     * A new link, the old one no longer works.
     *
     * @return string the new link's token, which is not kept
     */
    public function renew(\DateTimeImmutable $now): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokenHash = self::hash($token);
        $this->createdAt = $now;
        $this->expiresAt = $now->modify($this->isInvitation() ? self::INVITATION_VALID_FOR : self::VALID_FOR);

        return $token;
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
