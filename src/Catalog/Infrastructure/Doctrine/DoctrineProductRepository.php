<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\ClaimedSku;
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

    public function ids(int $afterId, int $limit): array
    {
        return self::intList($this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Product::class, 'p')
            ->where('p.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('p.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function idsOfferingOption(int $optionId): array
    {
        $option = $this->entityManager->find(AttributeOption::class, $optionId);
        if ($option === null) {
            return [];
        }
        $attribute = $option->attribute();
        $product = $attribute->product();
        $set = $attribute->set();

        return $product !== null ? [$product->id()] : ($set !== null ? $this->idsUsingSet($set->id()) : []);
    }

    public function optionsWithIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<AttributeOption> */
        return $this->entityManager->createQueryBuilder()
            ->select('o', 'a')
            ->from(AttributeOption::class, 'o')
            ->join('o.attribute', 'a')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function highestLowStockThreshold(): ?int
    {
        $highest = null;
        foreach ([Product::class, Variation::class] as $entity) {
            $most = $this->entityManager->createQueryBuilder()
                ->select('MAX(e.lowStockThreshold)')
                ->from($entity, 'e')
                ->getQuery()
                ->getSingleScalarResult();
            if (is_numeric($most)) {
                $highest = max($highest ?? 0, (int) $most);
            }
        }

        return $highest;
    }

    public function attributeNames(): array
    {
        $names = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a.name')
            ->from(Attribute::class, 'a')
            ->orderBy('a.name')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($names, \is_string(...)));
    }

    public function idsUsingSet(int $setId): array
    {
        return self::intList($this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Product::class, 'p')
            ->join('p.sets', 's')
            ->where('s.id = :set')
            ->setParameter('set', $setId)
            ->orderBy('p.id')
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function bundlesHolding(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $metadata = $this->entityManager->getClassMetadata(Product::class);
        $table = $metadata->getTableName();
        $id = $metadata->getColumnName('id');
        $bundle = $metadata->getColumnName('bundle');
        $ids = [];
        // An item is an object of the bundle's items list: {"product_id": 12, ...}.
        foreach (array_chunk(array_values(array_unique($productIds)), 100) as $chunk) {
            $holds = implode(' OR ', array_fill(0, \count($chunk), "JSON_CONTAINS({$bundle}, ?, '\$.items')"));
            $found = $this->entityManager->getConnection()->fetchFirstColumn(
                "SELECT {$id} FROM {$table} WHERE {$bundle} IS NOT NULL AND ({$holds})",
                array_map(static fn(int $productId): string => json_encode(['product_id' => $productId], \JSON_THROW_ON_ERROR), $chunk),
            );
            foreach ($found as $bundleId) {
                if (is_numeric($bundleId)) {
                    $ids[(int) $bundleId] = true;
                }
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    public function findByVariation(int $variationId): ?Product
    {
        return $this->entityManager->find(Variation::class, $variationId)?->product();
    }

    /**
     * Saves the product and the SKUs it and its variations have, together: one the database already holds for
     * another product or variation is refused, and nothing is saved.
     *
     * @throws CatalogRuleViolation for a SKU another has, as when two saves took it at once
     */
    public function save(Product $product): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->persist($product);
            $this->entityManager->flush();
            $this->claimSkus($product);
            $connection->commit();
        } catch (UniqueConstraintViolationException $e) {
            $connection->rollBack();

            throw new CatalogRuleViolation('Another product or variation has one of these SKUs.', 'orillacart_duplicate_sku', $e);
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function claimSkus(Product $product): void
    {
        $table = $this->entityManager->getClassMetadata(ClaimedSku::class)->getTableName();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("DELETE FROM {$table} WHERE product_id = ?", [$product->id()]);
        $claims = [];
        if ($product->details()->sku !== null) {
            $claims[] = [$product->details()->sku, $product->id(), null];
        }
        foreach ($product->variations() as $variation) {
            if ($variation->sku() !== null) {
                $claims[] = [$variation->sku(), $product->id(), $variation->id()];
            }
        }
        foreach ($claims as $claim) {
            $connection->executeStatement("INSERT INTO {$table} (sku, product_id, variation_id) VALUES (?, ?, ?)", $claim);
        }
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
        // One statement on the table of SKUs, where products' and variations' are side by side.
        $table = $this->entityManager->getClassMetadata(ClaimedSku::class)->getTableName();

        $count = $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE sku = ? AND NOT (product_id <=> ? AND variation_id IS NULL) AND NOT (variation_id <=> ?)",
            [$sku, $exceptProductId ?? -1, $exceptVariationId ?? -1],
        );

        return is_numeric($count) && (int) $count > 0;
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

    /**
     * @param array<mixed> $column
     *
     * @return list<int>
     */
    private static function intList(array $column): array
    {
        return array_values(array_map(intval(...), array_filter($column, is_numeric(...))));
    }
}
