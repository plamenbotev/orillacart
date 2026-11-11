<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * A record of a kind an extension declared (`object "ticket" { fields … }`): its data as the script gave it,
 * and the product, order or customer it refers to, as columns, so a deleted order takes its records with it and
 * an erased customer leaves none behind.
 */
#[ORM\Entity]
#[ORM\Table(name: 'extension_objects')]
#[ORM\Index(name: 'extension_object_type', columns: ['extension', 'type', 'id'])]
#[ORM\Index(name: 'extension_object_product', columns: ['product_id'])]
#[ORM\Index(name: 'extension_object_order', columns: ['order_id'])]
#[ORM\Index(name: 'extension_object_customer', columns: ['customer_id'])]
class ExtensionObject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(nullable: true)]
    #[References('products', 'CASCADE')]
    private ?int $productId = null;

    #[ORM\Column(nullable: true)]
    #[References('orders', 'CASCADE')]
    private ?int $orderId = null;

    #[ORM\Column(nullable: true)]
    private ?int $customerId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        #[ORM\Column(length: 40)]
        private string $extension,
        #[ORM\Column(length: 40)]
        private string $type,
        array $data,
        \DateTimeImmutable $at,
    ) {
        $this->createdAt = $at;
        $this->change($data, $at);
    }

    /** @param array<string, mixed> $data */
    public function change(array $data, \DateTimeImmutable $at): void
    {
        $this->data = $data;
        $this->productId = self::reference($data, 'product');
        $this->orderId = self::reference($data, 'order');
        $this->customerId = self::reference($data, 'customer');
        $this->updatedAt = $at;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The object is not saved yet.');
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * The record as a script sees it: its data with id, type and the moments.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data + ['id' => $this->id, 'type' => $this->type, 'created_at' => $this->createdAt->format(\DATE_ATOM), 'updated_at' => $this->updatedAt->format(\DATE_ATOM)];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function reference(array $data, string $kind): ?int
    {
        foreach ($data as $value) {
            if (\is_array($value) && ($value['$ref'] ?? null) === $kind && \is_int($value['id'] ?? null)) {
                return $value['id'];
            }
        }
        $value = $data[$kind] ?? null;

        return \is_int($value) && $value > 0 ? $value : null;
    }
}
