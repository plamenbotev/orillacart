<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\CatalogRuleViolation;
use OrillaCart\Catalog\Domain\ClaimedSku;
use OrillaCart\Catalog\Domain\DownloadFile;
use OrillaCart\Catalog\Domain\MarkedProduct;
use OrillaCart\Catalog\Domain\PriceVisibility;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductCriteria;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductType;
use OrillaCart\Catalog\Domain\StockMode;
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

        /** @var list<Product> $products */
        $products = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        if (\count($products) > 1) {
            // A list's offers read every product's variations, attributes, sets and files: one query for each kind
            // fills them for all, rather than several queries a product. Ordered as the lazy loads order them.
            $fill = fn(string $joins, string $order): mixed => $this->entityManager->createQuery(
                "SELECT p, {$joins} FROM " . Product::class . " p {$order}",
            )->setParameter('ids', $ids)->getResult();
            $fill('v, vo', 'LEFT JOIN p.variations v LEFT JOIN v.options vo WHERE p.id IN (:ids) ORDER BY v.id ASC');
            $fill('a, ao', 'LEFT JOIN p.attributes a LEFT JOIN a.options ao WHERE p.id IN (:ids) ORDER BY a.id ASC, ao.position ASC, ao.id ASC');
            $fill('s, sa, sao', 'LEFT JOIN p.sets s LEFT JOIN s.attributes sa LEFT JOIN sa.options sao WHERE p.id IN (:ids) ORDER BY s.id ASC, sa.id ASC, sao.position ASC, sao.id ASC');
            $fill('d', 'LEFT JOIN p.downloads d WHERE p.id IN (:ids) ORDER BY d.id ASC');
        }

        return $products;
    }

    public function withSku(string $sku): ?array
    {
        $product = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Product::class, 'p')
            ->where('p.sku = :sku')
            ->setParameter('sku', $sku)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();
        if (is_numeric($product[0] ?? null)) {
            return [(int) $product[0], null];
        }
        $variation = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(v.product) AS product, v.id AS variation')
            ->from(Variation::class, 'v')
            ->where('v.sku = :sku')
            ->setParameter('sku', $sku)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();
        $row = $variation[0] ?? null;

        return \is_array($row) && is_numeric($row['product'] ?? null) && is_numeric($row['variation'] ?? null) ? [(int) $row['product'], (int) $row['variation']] : null;
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

    public function existing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return self::intList($this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult());
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

    public function idsMatching(ProductCriteria $criteria): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('p.id')->from(Product::class, 'p')->orderBy('p.id');
        $variations = 'SELECT 1 FROM ' . Variation::class . ' %1$s WHERE %1$s.product = p';
        $anyVariation = static fn(string $alias, string $condition): string => \sprintf('EXISTS (' . $variations . ' AND (%2$s))', $alias, str_replace('{v}', $alias, $condition));
        $noVariations = static fn(string $alias): string => 'NOT EXISTS (' . \sprintf($variations, $alias) . ')';
        $onSale = '{v}.pricing.salePrice IS NOT NULL AND ({v}.pricing.saleFrom IS NULL OR {v}.pricing.saleFrom <= :now) AND ({v}.pricing.saleUntil IS NULL OR {v}.pricing.saleUntil > :now)';
        $charged = 'CASE WHEN ' . $onSale . ' THEN {v}.pricing.salePrice ELSE {v}.pricing.price END';
        $usesNow = false;

        match ($criteria->kind) {
            ProductCriteria::SHIPS => $query->andWhere('p.type = :type')->setParameter('type', ProductType::Physical->value),
            ProductCriteria::DOES_NOT_SHIP => $query->andWhere('p.type = :type')->setParameter('type', ProductType::Virtual->value),
            ProductCriteria::WITH_VARIATIONS => $query->andWhere(\sprintf('EXISTS (' . $variations . ')', 'kv')),
            ProductCriteria::BUNDLE => $query->andWhere('p.bundle IS NOT NULL'),
            ProductCriteria::WITH_DOWNLOADS => $query->andWhere('EXISTS (SELECT 1 FROM ' . DownloadFile::class . ' d WHERE d.product = p)'),
            default => null,
        };
        switch ($criteria->shown) {
            case ProductCriteria::FEATURED:
                $query->andWhere('p.featured = true');
                break;
            case ProductCriteria::NOT_PURCHASABLE:
                $query->andWhere('p.purchasable = false');
                break;
            case ProductCriteria::PRICE_HIDDEN:
                $query->andWhere($criteria->shopHidesPrices ? 'p.priceVisibility IN (:hidden)' : 'p.priceVisibility = :hidden')
                    ->setParameter('hidden', $criteria->shopHidesPrices ? [PriceVisibility::Hidden->value, PriceVisibility::Shop->value] : PriceVisibility::Hidden->value);
                break;
            case ProductCriteria::ON_SALE:
                $query->andWhere('(' . str_replace('{v}', 'p', $onSale) . ') OR ' . $anyVariation('sv', $onSale));
                $usesNow = true;
                break;
        }
        foreach (['>=' => $criteria->minPrice, '<=' => $criteria->maxPrice] as $operator => $price) {
            if ($price === null) {
                continue;
            }
            $name = $operator === '>=' ? 'min' : 'max';
            $query->andWhere(\sprintf('(%s AND %s %s :%s) OR %s', $noVariations($name . 'n'), str_replace('{v}', 'p', $charged), $operator, $name, $anyVariation($name . 'v', "{$charged} {$operator} :{$name}")))
                ->setParameter($name, $price->toFixed());
            $usesNow = true;
        }
        if ($criteria->shippingClass !== null) {
            $criteria->shippingClass === 0
                ? $query->andWhere('p.shippingClassId IS NULL')
                : $query->andWhere('p.shippingClassId = :class')->setParameter('class', $criteria->shippingClass);
        }
        if ($criteria->attributeSet !== null) {
            $query->andWhere(':set MEMBER OF p.sets')->setParameter('set', $criteria->attributeSet);
        }
        match ($criteria->missing) {
            ProductCriteria::NO_SKU => $query->andWhere('p.sku IS NULL OR ' . $anyVariation('mv', '{v}.sku IS NULL')),
            // A bundle priced by its items has no price of its own to miss.
            ProductCriteria::NO_PRICE => $query->andWhere('(' . $noVariations('mn') . ' AND p.pricing.price = 0 AND (p.bundle IS NULL OR p.id IN (:fixed))) OR ' . $anyVariation('mv', '{v}.pricing.price = 0'))
                ->setParameter('fixed', [0, ...array_column(array_filter($this->bundles(), static fn(array $row): bool => $row['bundle']->pricing === Bundle::FIXED), 'product')]),
            // A variation without its own weight has its product's.
            ProductCriteria::NO_WEIGHT => $query->andWhere('p.type = :type AND p.bundle IS NULL AND p.weight IS NULL')
                ->andWhere($noVariations('mn') . ' OR ' . $anyVariation('mv', '{v}.weight IS NULL'))
                ->setParameter('type', ProductType::Physical->value),
            default => null,
        };
        if ($criteria->addOnMark !== null) {
            $query->andWhere('EXISTS (SELECT 1 FROM ' . MarkedProduct::class . ' am WHERE am.productId = p.id AND am.mark = :addOnMark)')
                ->setParameter('addOnMark', $criteria->addOnMark);
        }
        if ($usesNow) {
            $query->setParameter('now', $criteria->now, 'datetime_immutable');
        }

        return self::intList($query->getQuery()->getSingleColumnResult());
    }

    public function mark(int $productId, string $mark): bool
    {
        $marked = new MarkedProduct($productId, $mark);
        if ($this->entityManager->find(Product::class, $productId) === null) {
            return false;
        }
        if (!$this->isMarked($productId, $mark)) {
            try {
                $this->entityManager->persist($marked);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // Marked by another request meanwhile: it is marked.
            }
        }

        return true;
    }

    public function unmark(int $productId, string $mark): void
    {
        $this->entityManager->createQueryBuilder()->delete(MarkedProduct::class, 'm')
            ->where('m.productId = :product AND m.mark = :mark')
            ->setParameter('product', $productId)
            ->setParameter('mark', $mark)
            ->getQuery()
            ->execute();
    }

    public function isMarked(int $productId, string $mark): bool
    {
        return $this->entityManager->getRepository(MarkedProduct::class)->count(['productId' => $productId, 'mark' => $mark]) > 0;
    }

    public function stockedItems(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS product', 'v.id AS variation', 'p.stockMode AS productMode', 'v.stockMode AS variationMode')
            ->from(Product::class, 'p')
            ->leftJoin('p.variations', 'v')
            ->where('p.bundle IS NULL')
            ->orderBy('p.id')
            ->addOrderBy('v.id')
            ->getQuery()
            ->getArrayResult();
        $items = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !is_numeric($row['product'] ?? null)) {
                continue;
            }
            $variation = is_numeric($row['variation'] ?? null) ? (int) $row['variation'] : null;
            $own = static fn(mixed $mode): ?StockMode => $mode instanceof StockMode ? $mode : StockMode::tryFrom(\is_string($mode) ? $mode : '');
            $productMode = $own($row['productMode'] ?? null);
            // A product not available as a whole sells none of its variations.
            $mode = $variation === null || $productMode === StockMode::Unavailable ? $productMode : $own($row['variationMode'] ?? null);
            if ($mode !== null) {
                $items[] = ['product' => (int) $row['product'], 'variation' => $variation, 'mode' => $mode];
            }
        }

        return $items;
    }

    public function bundles(): array
    {
        /** @var list<Product> $products */
        $products = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.bundle IS NOT NULL')
            ->orderBy('p.id')
            ->getQuery()
            ->getResult();
        $bundles = [];
        foreach ($products as $product) {
            $bundle = $product->bundle();
            if ($bundle !== null) {
                $bundles[] = ['product' => $product->id(), 'mode' => $product->details()->stockMode, 'bundle' => $bundle];
            }
        }

        return $bundles;
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

    public function countTaxedBy(int $taxGroupId): int
    {
        return $this->entityManager->getRepository(Product::class)->count(['taxGroupId' => $taxGroupId]);
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

    public function lockedUpdatedAt(int $id): ?string
    {
        $found = $this->entityManager->createQuery('SELECT p.updatedAt FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();
        $value = \is_array($found[0] ?? null) ? ($found[0]['updatedAt'] ?? null) : null;

        return is_numeric($value) ? Product::stamp((int) $value) : null;
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
