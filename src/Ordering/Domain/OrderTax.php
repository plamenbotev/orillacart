<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\TaxJurisdiction;

/**
 * One rate charged on one line of an order: who it is owed to, what it was charged on before tax, how much, and
 * how much of it refunds gave back. Returns are filed from these, by jurisdiction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_taxes')]
#[ORM\Index(name: 'order_tax_jurisdiction', columns: ['country', 'region', 'code'])]
class OrderTax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 4)]
    private string $percent;

    #[ORM\Column]
    private bool $compound;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $region;

    #[ORM\Column(length: 10)]
    private string $level;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $jurisdiction;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $taxable;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, options: ['default' => '0.0000'])]
    private string $refunded = '0';

    /** Not charged: the customer's exemption covered the sale. */
    #[ORM\Column(options: ['default' => false])]
    private bool $exempt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: OrderLine::class, inversedBy: 'taxes')]
        #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
        private OrderLine $line,
        LineTax $tax,
    ) {
        $this->name = mb_substr($tax->name, 0, 64);
        $this->percent = $tax->percent;
        $this->compound = $tax->compound;
        $this->country = $tax->jurisdiction->country;
        $this->region = $tax->jurisdiction->region;
        $this->level = $tax->jurisdiction->level;
        $this->code = mb_substr($tax->jurisdiction->code, 0, 60);
        $this->jurisdiction = mb_substr($tax->jurisdiction->name, 0, 100);
        $this->taxable = $tax->taxable->toFixed();
        $this->amount = $tax->amount->toFixed();
        $this->exempt = $tax->exempt;
    }

    public function isExempt(): bool
    {
        return $this->exempt;
    }

    public function line(): OrderLine
    {
        return $this->line;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function percent(): string
    {
        return (string) Money::of($this->percent);
    }

    public function isCompound(): bool
    {
        return $this->compound;
    }

    public function jurisdiction(): TaxJurisdiction
    {
        return new TaxJurisdiction($this->country, $this->region, $this->level, $this->code, $this->jurisdiction);
    }

    public function taxable(): Money
    {
        return Money::of($this->taxable);
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }

    public function refunded(): Money
    {
        return Money::of($this->refunded);
    }

    /** @internal for OrderLine, as a refund gives some of it back */
    public function giveBack(Money $amount): void
    {
        $this->refunded = $this->refunded()->add($amount)->toFixed();
    }
}
