<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Someone who signed up for the shop's newsletter: what they agreed to, when and where. Unsubscribing keeps the
 * record, with when they left, until the privacy tools erase the person.
 */
#[ORM\Entity]
#[ORM\Table(name: 'newsletter_subscribers')]
#[ORM\UniqueConstraint(name: 'newsletter_subscriber_email', columns: ['email'])]
#[ORM\Index(name: 'newsletter_subscribers_in', columns: ['unsubscribed_at'])]
class Subscriber
{
    public const int MAX_CONSENT_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: self::MAX_CONSENT_LENGTH)]
    private string $consent;

    #[ORM\Column(length: 20, enumType: SignUpSource::class)]
    private SignUpSource $source;

    #[ORM\Column]
    private \DateTimeImmutable $subscribedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $customerId;

    /** Signed up, waiting for its owner to confirm from the email: not on the newsletter yet. */
    #[ORM\Column(options: ['default' => false])]
    private bool $pending = false;

    /** When its owner confirmed it from the email; null for one the shop did not ask to confirm. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /**
     * @param bool $confirm whether it waits for its owner to confirm it from the email
     *
     * @throws NewsletterRuleViolation without the words they agreed to
     */
    public function __construct(string $email, string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at, ?int $customerId, bool $confirm = false)
    {
        $this->email = self::normalize($email);
        $this->customerId = $customerId;
        $this->signUp($firstName, $lastName, $consent, $source, $at, $confirm);
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * They sign up again: one who left agrees anew, to these words and from now, as one still waiting to confirm
     * does; one subscribed keeps what they first agreed to.
     *
     * @param bool $confirm whether it waits for its owner to confirm it from the email
     *
     * @return bool whether they were not subscribed
     *
     * @throws NewsletterRuleViolation without the words they agreed to
     */
    public function subscribe(string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at, ?int $customerId, bool $confirm = false): bool
    {
        $this->customerId ??= $customerId;
        if ($this->isSubscribed()) {
            return false;
        }
        $this->signUp($firstName, $lastName, $consent, $source, $at, $confirm);

        return true;
    }

    /**
     * Its owner followed the link emailed to them: on the newsletter from now.
     *
     * @return bool false for one not waiting to be confirmed
     */
    public function confirm(\DateTimeImmutable $at): bool
    {
        if (!$this->isPending()) {
            return false;
        }
        $this->pending = false;
        $this->confirmedAt = $at;

        return true;
    }

    /** Signed up and waiting for its owner to confirm it from the email. */
    public function isPending(): bool
    {
        return $this->unsubscribedAt === null && $this->pending;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    private function signUp(string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at, bool $confirm): void
    {
        $consent = trim($consent);
        if ($consent === '' || mb_strlen($consent) > self::MAX_CONSENT_LENGTH) {
            throw new NewsletterRuleViolation('A sign-up keeps the words agreed to, in 1 to %d characters.', 'orillacart_invalid_consent', values: [self::MAX_CONSENT_LENGTH]);
        }
        $this->firstName = mb_substr(trim($firstName), 0, 100);
        $this->lastName = mb_substr(trim($lastName), 0, 100);
        $this->consent = $consent;
        $this->source = $source;
        $this->subscribedAt = $at;
        $this->unsubscribedAt = null;
        $this->pending = $confirm;
        $this->confirmedAt = null;
    }

    /** Whether it changed anything: one waiting to be confirmed is withdrawn, and its link confirms nothing. */
    public function unsubscribe(\DateTimeImmutable $at): bool
    {
        if ($this->unsubscribedAt !== null) {
            return false;
        }
        $this->unsubscribedAt = $at;

        return true;
    }

    /** The person changed their email address: the sign-up follows it. */
    public function changeEmail(string $email): void
    {
        $this->email = self::normalize($email);
    }

    /** On the newsletter: signed up, confirmed when the shop asked for it, and not left. */
    public function isSubscribed(): bool
    {
        return $this->unsubscribedAt === null && !$this->pending;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The subscriber has no id until it is saved.');
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

    public function consent(): string
    {
        return $this->consent;
    }

    public function source(): SignUpSource
    {
        return $this->source;
    }

    public function subscribedAt(): \DateTimeImmutable
    {
        return $this->subscribedAt;
    }

    public function unsubscribedAt(): ?\DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }
}
