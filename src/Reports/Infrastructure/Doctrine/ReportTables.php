<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Reports\Domain\GivenRefund;
use OrillaCart\Reports\Domain\SoldLine;
use OrillaCart\Reports\Domain\SoldOrder;
use OrillaCart\Reports\Domain\UsedCoupon;

/**
 * The connection and the prefixed names of the report tables, and of the WordPress tables reports name products
 * and categories from.
 */
final readonly class ReportTables
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function orders(): string
    {
        return $this->entityManager->getClassMetadata(SoldOrder::class)->getTableName();
    }

    public function lines(): string
    {
        return $this->entityManager->getClassMetadata(SoldLine::class)->getTableName();
    }

    public function coupons(): string
    {
        return $this->entityManager->getClassMetadata(UsedCoupon::class)->getTableName();
    }

    public function refunds(): string
    {
        return $this->entityManager->getClassMetadata(GivenRefund::class)->getTableName();
    }

    /**
     * A WordPress table of the site.
     *
     * @param 'posts'|'terms'|'term_taxonomy'|'term_relationships' $name
     */
    public function wordpress(string $name): string
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \LogicException('The reports name products from WordPress, which is not loaded.');
        }

        return match ($name) {
            'posts' => $wpdb->posts,
            'terms' => $wpdb->terms,
            'term_taxonomy' => $wpdb->term_taxonomy,
            'term_relationships' => $wpdb->term_relationships,
        };
    }
}
