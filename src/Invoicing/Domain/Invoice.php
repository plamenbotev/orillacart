<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\References;

/**
 * An invoice of a paid order, or a credit note for money given back. Its number is taken when it is issued,
 * in the same transaction as the payment or the refund, from a series that never skips a number. It keeps the
 * seller's details as they were then; the rest it shows comes from the order.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoices')]
#[ORM\UniqueConstraint(name: 'invoice_number', columns: ['number'])]
#[ORM\Index(name: 'invoice_order', columns: ['order_id'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    /**
     * @param array<string, string>           $seller   InvoiceSettings::seller() when it was issued
     * @param list<array{line: int, quantity: int}> $lines the order lines a credit note gives back, if any
     * @param list<array{name: string, percent: string, amount: string}> $taxes the tax a credit note gives back, by rate
     */
    public function __construct(
        #[ORM\Column]
        #[References('orders')]
        private int $orderId,
        #[ORM\Column(length: 20, enumType: InvoiceKind::class)]
        private InvoiceKind $kind,
        #[ORM\Column(length: 40)]
        private string $number,
        Money $amount,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(type: 'json')]
        private array $seller,
        #[ORM\Column]
        private \DateTimeImmutable $issuedAt,
        // LINES is a reserved word of MySQL.
        #[ORM\Column(name: 'credited_lines', type: 'json')]
        private array $lines = [],
        #[ORM\Column(type: 'json')]
        private array $taxes = [],
    ) {
        $this->amount = $amount->toFixed();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The invoice has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function kind(): InvoiceKind
    {
        return $this->kind;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** @return array<string, string> */
    public function seller(): array
    {
        return $this->seller;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    /** @return list<array{line: int, quantity: int}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<array{name: string, percent: string, amount: string}> for a credit note: the tax it gives back */
    public function taxes(): array
    {
        return $this->taxes;
    }
}
