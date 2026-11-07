<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One indexed value: of a field an extension declared on one of the shop's own records (`fields on product`),
 * or of an indexed field of an extension's object, so a query filters and sorts by it in the database. Text,
 * number and date columns, the one the field's kind fills.
 */
#[ORM\Entity]
#[ORM\Table(name: 'extension_values')]
#[ORM\UniqueConstraint(name: 'extension_value_owner', columns: ['extension', 'kind', 'owner_id', 'field'])]
#[ORM\Index(name: 'extension_value_text', columns: ['extension', 'kind', 'field', 'value_text'])]
#[ORM\Index(name: 'extension_value_number', columns: ['extension', 'kind', 'field', 'value_number'])]
#[ORM\Index(name: 'extension_value_date', columns: ['extension', 'kind', 'field', 'value_date'])]
class ExtensionValue
{
    public const string OBJECT = 'object';
    public const int MAX_TEXT = 191;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_TEXT, nullable: true)]
    private ?string $valueText = null;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, nullable: true)]
    private ?string $valueNumber = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valueDate = null;

    /** @var array<string, mixed>|null the whole value, for a field on a product, order or customer */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $value = null;

    public function __construct(
        #[ORM\Column(length: 40)]
        private string $extension,
        /** `object`, or the shop's kind: `product`, `order`, `customer`. */
        #[ORM\Column(length: 20)]
        private string $kind,
        /** The object's id, or the product's, order's or customer's. */
        #[ORM\Column]
        private int $ownerId,
        #[ORM\Column(length: 60)]
        private string $field,
        mixed $value,
    ) {
        $this->set($value);
    }

    public function set(mixed $value): void
    {
        $this->value = ['v' => $value];
        $this->valueText = null;
        $this->valueNumber = null;
        $this->valueDate = null;
        if (\is_bool($value)) {
            $this->valueNumber = $value ? '1' : '0';
        } elseif (\is_int($value) || \is_float($value)) {
            $this->valueNumber = number_format($value, 4, '.', '');
        } elseif (\is_string($value)) {
            $this->valueText = mb_substr($value, 0, self::MAX_TEXT);
            if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/', $value) === 1) {
                try {
                    $this->valueDate = new \DateTimeImmutable($value);
                } catch (\Exception) {
                    // Looked like a date, was not one: text it stays.
                }
            } elseif (is_numeric($value)) {
                $this->valueNumber = number_format((float) $value, 4, '.', '');
            }
        }
    }

    public function field(): string
    {
        return $this->field;
    }

    public function ownerId(): int
    {
        return $this->ownerId;
    }

    public function value(): mixed
    {
        return $this->value['v'] ?? null;
    }
}
