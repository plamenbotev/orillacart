<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A file buyers of a product may download once their order is paid. The file itself is in the shop's private
 * file store, under the storage key; this is its name and what it is.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_downloads')]
class DownloadFile
{
    public const int MAX_NAME_LENGTH = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'downloads')]
        #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        string $name,
        #[ORM\Column(length: 32)]
        private string $storageKey,
        #[ORM\Column(length: 100)]
        private string $mimeType,
        #[ORM\Column]
        private int $size,
    ) {
        $this->rename($name);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The download has no id until it is saved.');
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function product(): Product
    {
        return $this->product;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation(\sprintf('A download needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_download_name');
        }
        $this->name = $name;
    }
}
