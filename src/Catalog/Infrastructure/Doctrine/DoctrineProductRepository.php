<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\Variation;

final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Product
    {
        return $this->entityManager->find(Product::class, $id);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Product> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function findByVariation(int $variationId): ?Product
    {
        return $this->entityManager->find(Variation::class, $variationId)?->product();
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }

    public function remove(Product $product): void
    {
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }

    public function isSkuTaken(string $sku, ?int $exceptProductId = null, ?int $exceptVariationId = null): bool
    {
        $products = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.sku = :sku')
            ->setParameter('sku', $sku);
        if ($exceptProductId !== null) {
            $products->andWhere('p.id <> :product')->setParameter('product', $exceptProductId);
        }
        $variations = $this->entityManager->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(Variation::class, 'v')
            ->where('v.sku = :sku')
            ->setParameter('sku', $sku);
        if ($exceptVariationId !== null) {
            $variations->andWhere('v.id <> :variation')->setParameter('variation', $exceptVariationId);
        }

        return (int) $products->getQuery()->getSingleScalarResult() + (int) $variations->getQuery()->getSingleScalarResult() > 0;
    }

    public function isAnyOptionUsedByVariations(array $optionIds): bool
    {
        if ($optionIds === []) {
            return false;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(Variation::class, 'v')
            ->join('v.options', 'o')
            ->where('o.id IN (:options)')
            ->setParameter('options', $optionIds)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function isSetUsedByVariations(AttributeSet $set): bool
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(Variation::class, 'v')
            ->join('v.options', 'o')
            ->join('o.attribute', 'a')
            ->where('a.set = :set')
            ->setParameter('set', $set)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
