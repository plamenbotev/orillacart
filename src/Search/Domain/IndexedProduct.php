<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A product as the database search engine keeps it: what the text search and the sorts read. Its variants and
 * values are rows of their own. The engine writes and reads these rows with SQL (FULLTEXT and facet counts are
 * beyond the ORM); this mapping makes the migrations create the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search_documents')]
#[ORM\Index(name: 'search_title', columns: ['title'], flags: ['fulltext'])]
#[ORM\Index(name: 'search_text', columns: ['title', 'text'], flags: ['fulltext'])]
#[ORM\Index(name: 'search_order', columns: ['menu_order', 'title'])]
#[ORM\Index(name: 'search_published', columns: ['published_at'])]
#[ORM\Index(name: 'search_price', columns: ['price_min'])]
#[ORM\Index(name: 'search_price_max', columns: ['price_max'])]
class IndexedProduct
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $productId,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $text,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
        private ?string $priceMin,
        #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
        private ?string $priceMax,
        #[ORM\Column]
        private bool $inStock,
        #[ORM\Column]
        private bool $featured,
        #[ORM\Column]
        private bool $onSale,
        #[ORM\Column]
        private int $menuOrder,
        #[ORM\Column]
        private \DateTimeImmutable $publishedAt,
    ) {}

    public function productId(): int
    {
        return $this->productId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @return array{?string, ?string} */
    public function prices(): array
    {
        return [$this->priceMin, $this->priceMax];
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function isOnSale(): bool
    {
        return $this->onSale;
    }

    public function menuOrder(): int
    {
        return $this->menuOrder;
    }

    public function publishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
