<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\ORMSetup;
use OrillaCart\Platform\Doctrine\TablePrefixListener;
use OrillaCart\Tests\Fixtures\Entity\Product;
use OrillaCart\Tests\Fixtures\Entity\Tag;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TablePrefixListenerTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [\dirname(__DIR__, 3) . '/Fixtures/Entity'],
            isDevMode: true,
            cache: new ArrayAdapter(),
        );
        $config->enableNativeLazyObjects(true);

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener('wp_orillacart_'));

        // Metadata loading needs no real database; the SQLite driver only provides a platform.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->entityManager = new EntityManager($connection, $config, $events);
    }

    public function testPrefixesEntityTables(): void
    {
        self::assertSame('wp_orillacart_products', $this->entityManager->getClassMetadata(Product::class)->getTableName());
        self::assertSame('wp_orillacart_tags', $this->entityManager->getClassMetadata(Tag::class)->getTableName());
    }

    public function testPrefixesManyToManyJoinTables(): void
    {
        $mapping = $this->entityManager->getClassMetadata(Product::class)->associationMappings['tags'];

        self::assertInstanceOf(ManyToManyOwningSideMapping::class, $mapping);
        self::assertSame('wp_orillacart_product_tags', $mapping->joinTable->name);
    }

    public function testLoadingMetadataTwiceDoesNotPrefixTwice(): void
    {
        $this->entityManager->getClassMetadata(Product::class);
        $this->entityManager->getMetadataFactory()->setMetadataFor(Product::class, $this->entityManager->getClassMetadata(Product::class));

        self::assertSame('wp_orillacart_products', $this->entityManager->getClassMetadata(Product::class)->getTableName());
    }
}
