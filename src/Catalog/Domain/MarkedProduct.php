<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * An add-on's mark on a product, such as "listed on the marketplace": recorded when it happens, so the products
 * list filters by it in the database. Gone with the product.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_marks')]
#[ORM\UniqueConstraint(name: 'product_mark', columns: ['product_id', 'mark'])]
#[ORM\Index(name: 'mark_product', columns: ['mark', 'product_id'])]
class MarkedProduct
{
    /** As add-ons' filter ids are. */
    public const string MARK = '/^[a-z][a-z0-9_]{0,39}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        #[References('products', 'CASCADE')]
        private int $productId,
        #[ORM\Column(length: 40)]
        private string $mark,
    ) {
        if (preg_match(self::MARK, $mark) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a filter id: lowercase letters, digits and underscores, up to 40.', $mark));
        }
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function mark(): string
    {
        return $this->mark;
    }
}
