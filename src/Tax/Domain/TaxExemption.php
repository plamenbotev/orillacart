<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A customer's exemption from tax: why, the certificate the shop keeps on file, where it holds (countries or
 * states; everywhere when none), and until when. Orders it covers are taxed at nothing, and keep it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tax_exemptions')]
#[ORM\Index(name: 'exemption_customer', columns: ['customer_id'])]
class TaxExemption
{
    public const int MAX_CERTIFICATE_LENGTH = 64;
    public const int MAX_NOTE_LENGTH = 200;
    /** A country ("US") or one of its states ("US-TX"). */
    public const string REGION = '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ExemptionReason::class)]
    private ExemptionReason $reason;

    #[ORM\Column(length: self::MAX_CERTIFICATE_LENGTH)]
    private string $certificate;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $regions;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresOn;

    #[ORM\Column(length: self::MAX_NOTE_LENGTH)]
    private string $note;

    #[ORM\Column(length: 20, enumType: ExemptionStatus::class)]
    private ExemptionStatus $status = ExemptionStatus::Approved;

    /** Why the shop refused it, for the customer. */
    #[ORM\Column(length: self::MAX_NOTE_LENGTH, nullable: true)]
    private ?string $refusal = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** The customer was told it runs out soon. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    /** The copy of the certificate the customer sent, in the shop's private files: its key, name and type. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fileKey = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fileType = null;

    /**
     * @param list<string> $regions countries and states it holds in; empty for everywhere
     */
    public function __construct(
        #[ORM\Column]
        private int $customerId,
        ExemptionReason $reason,
        string $certificate,
        array $regions,
        ?\DateTimeImmutable $expiresOn,
        string $note,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $certificate = trim($certificate);
        if ($certificate === '' || mb_strlen($certificate) > self::MAX_CERTIFICATE_LENGTH) {
            throw new RuleViolation('An exemption needs its certificate\'s number, up to %d characters.', 'orillacart_invalid_exemption', values: [self::MAX_CERTIFICATE_LENGTH]);
        }
        foreach ($regions as $region) {
            if (preg_match(self::REGION, $region) !== 1) {
                throw new RuleViolation('"%s" is not a country or a state, such as US or US-TX.', 'orillacart_invalid_exemption', values: [$region]);
            }
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new RuleViolation('A note has up to %d characters.', 'orillacart_invalid_exemption', values: [self::MAX_NOTE_LENGTH]);
        }
        $this->reason = $reason;
        $this->certificate = $certificate;
        $this->regions = array_values(array_unique($regions));
        $this->expiresOn = $expiresOn?->setTime(0, 0);
        $this->note = trim($note);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The exemption has no id until it is saved.');
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function reason(): ExemptionReason
    {
        return $this->reason;
    }

    public function certificate(): string
    {
        return $this->certificate;
    }

    /** @return list<string> */
    public function regions(): array
    {
        return $this->regions;
    }

    public function expiresOn(): ?\DateTimeImmutable
    {
        return $this->expiresOn;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function status(): ExemptionStatus
    {
        return $this->status;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    public function reviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function remindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }

    /** @return array{key: string, name: string, type: string}|null */
    public function file(): ?array
    {
        return $this->fileKey === null ? null : ['key' => $this->fileKey, 'name' => $this->fileName ?? 'certificate', 'type' => $this->fileType ?? 'application/octet-stream'];
    }

    /** A certificate the customer sent, with its copy: it waits for the shop to check it. */
    public function sentByCustomer(string $fileKey, string $fileName, string $fileType): void
    {
        $this->status = ExemptionStatus::Pending;
        $this->fileKey = $fileKey;
        $this->fileName = mb_substr($fileName, 0, 200);
        $this->fileType = $fileType;
    }

    /**
     * Approved, where it holds and until when as the shop read them on the copy, when it says so.
     *
     * @param list<string>|null $regions
     *
     * @throws RuleViolation for one that is not waiting, or a region that is not one
     */
    public function approve(\DateTimeImmutable $now, ?array $regions = null, ?\DateTimeImmutable $expiresOn = null, bool $setsEnd = false): void
    {
        foreach ($regions ?? [] as $region) {
            if (preg_match(self::REGION, $region) !== 1) {
                throw new RuleViolation('"%s" is not a country or a state, such as US or US-TX.', 'orillacart_invalid_exemption', values: [$region]);
            }
        }
        $this->review($now);
        $this->status = ExemptionStatus::Approved;
        if ($regions !== null) {
            $this->regions = array_values(array_unique($regions));
        }
        if ($setsEnd) {
            $this->expiresOn = $expiresOn?->setTime(0, 0);
        }
    }

    /**
     * @throws RuleViolation for one that is not waiting, or a reason too long
     */
    public function refuse(string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_NOTE_LENGTH) {
            throw new RuleViolation('Tell the customer why, in up to %d characters.', 'orillacart_invalid_exemption', values: [self::MAX_NOTE_LENGTH]);
        }
        $this->review($now);
        $this->status = ExemptionStatus::Refused;
        $this->refusal = $reason;
    }

    public function reminded(\DateTimeImmutable $now): void
    {
        $this->remindedAt = $now;
    }

    /** It runs out within the days given from today, and the customer was not told yet. */
    public function expiresSoon(\DateTimeImmutable $today, int $days): bool
    {
        return $this->status === ExemptionStatus::Approved && $this->remindedAt === null && $this->expiresOn !== null
            && $this->expiresOn->format('Y-m-d') >= $today->format('Y-m-d') && $this->expiresOn->format('Y-m-d') <= $today->modify("+{$days} days")->format('Y-m-d');
    }

    private function review(\DateTimeImmutable $now): void
    {
        if ($this->status !== ExemptionStatus::Pending) {
            throw new RuleViolation('The certificate was checked already.', 'orillacart_exemption_reviewed');
        }
        $this->reviewedAt = $now;
    }

    /**
     * Whether it holds for a sale taxed at the location on the day: approved, not expired (it holds through its last day), and
     * for that country or state.
     */
    public function covers(TaxLocation $location, \DateTimeImmutable $on): bool
    {
        if ($this->status !== ExemptionStatus::Approved) {
            return false;
        }
        if ($this->expiresOn !== null && $on->format('Y-m-d') > $this->expiresOn->format('Y-m-d')) {
            return false;
        }

        return $this->regions === [] || \in_array($location->country, $this->regions, true)
            || ($location->state !== null && \in_array("{$location->country}-{$location->state}", $this->regions, true));
    }
}
