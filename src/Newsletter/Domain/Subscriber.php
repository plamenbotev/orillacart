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

    /**
     * @throws NewsletterRuleViolation without the words they agreed to
     */
    public function __construct(string $email, string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at, ?int $customerId)
    {
        $this->email = self::normalize($email);
        $this->customerId = $customerId;
        $this->signUp($firstName, $lastName, $consent, $source, $at);
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * They sign up again: one who left agrees anew, to these words and from now; one still subscribed keeps
     * what they first agreed to.
     *
     * @return bool whether they were away
     *
     * @throws NewsletterRuleViolation without the words they agreed to
     */
    public function subscribe(string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at, ?int $customerId): bool
    {
        $this->customerId ??= $customerId;
        if ($this->isSubscribed()) {
            return false;
        }
        $this->signUp($firstName, $lastName, $consent, $source, $at);

        return true;
    }

    private function signUp(string $firstName, string $lastName, string $consent, SignUpSource $source, \DateTimeImmutable $at): void
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
    }

    /** Whether it changed anything. */
    public function unsubscribe(\DateTimeImmutable $at): bool
    {
        if (!$this->isSubscribed()) {
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

    public function isSubscribed(): bool
    {
        return $this->unsubscribedAt === null;
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
