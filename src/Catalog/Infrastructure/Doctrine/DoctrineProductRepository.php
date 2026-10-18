<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\AttributeOption;
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

    public function idsWithSku(string $text): array
    {
        $like = '%' . addcslashes($text, '%_\\') . '%';
        $products = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Product::class, 'p')
            ->where('p.sku LIKE :sku')
            ->setParameter('sku', $like)
            ->getQuery()
            ->getSingleColumnResult();
        $variations = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(v.product)')
            ->from(Variation::class, 'v')
            ->where('v.sku LIKE :sku')
            ->setParameter('sku', $like)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ([...$products, ...$variations] as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    public function options(?string $text, int $offset, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(AttributeOption::class, 'o')
            ->join('o.attribute', 'a')
            ->leftJoin('a.set', 's')
            ->leftJoin('a.product', 'p');
        if ($text !== null && $text !== '') {
            $query->where('o.name LIKE :text OR a.name LIKE :text OR s.name LIKE :text')
                ->setParameter('text', '%' . addcslashes($text, '%_\\') . '%');
        }
        $total = (int) (clone $query)->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        /** @var list<AttributeOption> $options */
        $options = $query->select('o', 'a')
            ->orderBy('s.name')
            ->addOrderBy('p.id')
            ->addOrderBy('a.position')
            ->addOrderBy('o.position')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [$options, $total];
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

    public function forgetShippingClass(int $shippingClassId): void
    {
        foreach ($this->entityManager->getRepository(Product::class)->findBy(['shippingClassId' => $shippingClassId]) as $product) {
            $product->leaveShippingClass();
        }
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
