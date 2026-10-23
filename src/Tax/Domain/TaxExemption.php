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
            throw new RuleViolation(\sprintf('An exemption needs its certificate\'s number, up to %d characters.', self::MAX_CERTIFICATE_LENGTH), 'orillacart_invalid_exemption');
        }
        foreach ($regions as $region) {
            if (preg_match(self::REGION, $region) !== 1) {
                throw new RuleViolation(\sprintf('"%s" is not a country or a state, such as US or US-TX.', $region), 'orillacart_invalid_exemption');
            }
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new RuleViolation(\sprintf('A note has up to %d characters.', self::MAX_NOTE_LENGTH), 'orillacart_invalid_exemption');
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

    /**
     * Whether it holds for a sale taxed at the location on the day: not expired (it holds through its last day), and
     * for that country or state.
     */
    public function covers(TaxLocation $location, \DateTimeImmutable $on): bool
    {
        if ($this->expiresOn !== null && $on->format('Y-m-d') > $this->expiresOn->format('Y-m-d')) {
            return false;
        }

        return $this->regions === [] || \in_array($location->country, $this->regions, true)
            || ($location->state !== null && \in_array("{$location->country}-{$location->state}", $this->regions, true));
    }
}
