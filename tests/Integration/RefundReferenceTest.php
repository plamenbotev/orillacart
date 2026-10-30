<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env. A provider names each refund once: the refunds table refuses a second row with the same
 * gateway and provider reference, so two notifications of one refund can never record it twice (item 239).
 */
final class RefundReferenceTest extends TestCase
{
    private Connection $connection;
    private string $table;

    protected function setUp(): void
    {
        $context = new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION);
        $migrator = new Kernel($context)->container()->get(DatabaseMigrator::class);
        self::assertInstanceOf(DatabaseMigrator::class, $migrator);
        $migrator->migrate();
        $database = DatabaseConfig::fromWordPress();
        $this->connection = new EntityManagerFactory($database, $context)->create()->getConnection();
        $this->table = $database->tablePrefix . 'refunds';
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement("DELETE FROM {$this->table} WHERE provider_reference = 'TEST-REF-239' OR order_id = 999999239");
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testAProviderReferenceIsUniquePerGateway(): void
    {
        // The index alone is under test: the rows name no order, so the order's foreign key is off for them.
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->insert('paypal', 'TEST-REF-239');
        $this->insert('stripe', 'TEST-REF-239');
        self::assertEquals(2, $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->table} WHERE provider_reference = 'TEST-REF-239'"), 'another gateway may use the same reference');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert('paypal', 'TEST-REF-239');
    }

    public function testTheIndexIsMadeWhereANotificationSentTwiceRecordedARefundTwice(): void
    {
        // As a site that ran the old migrations: the index not unique, and the rows the double notification made.
        $this->connection->executeStatement("DROP INDEX refund_reference ON {$this->table}");
        $this->connection->executeStatement("CREATE INDEX refund_reference ON {$this->table} (gateway, provider_reference)");
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $first = $this->insert('paypal', 'TEST-DUP-266');
        $second = $this->insert('paypal', 'TEST-DUP-266');
        $third = $this->insert('paypal', 'TEST-DUP-266');
        $other = $this->insert('stripe', 'TEST-DUP-266');
        $empty = [$this->insert('manual', ''), $this->insert('manual', '')];
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $migrations = DatabaseConfig::fromWordPress()->tablePrefix . 'migrations';
        self::assertSame(1, $this->connection->executeStatement("DELETE FROM {$migrations} WHERE version LIKE '%Version20261030112606'"));

        $this->migrator()->migrate();

        $references = $this->connection->fetchAllKeyValue("SELECT id, provider_reference FROM {$this->table} WHERE order_id = 999999239 ORDER BY id");
        self::assertSame(['TEST-DUP-266', null, null, 'TEST-DUP-266', null, null], array_values(array_map(static fn(mixed $reference): ?string => \is_string($reference) ? $reference : null, $references)));
        self::assertSame([$first, $second, $third, $other, ...$empty], array_map(intval(...), array_keys($references)));
        self::assertEquals(0, $this->connection->fetchOne("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'refund_reference' LIMIT 1", [$this->table]), 'the index is unique now');
    }

    private function migrator(): DatabaseMigrator
    {
        $migrator = new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->container()->get(DatabaseMigrator::class);
        self::assertInstanceOf(DatabaseMigrator::class, $migrator);

        return $migrator;
    }

    private function insert(string $gateway, string $reference): int
    {
        $this->connection->insert($this->table, [
            'order_id' => 999_999_239,
            'payment_id' => null,
            'gateway' => $gateway,
            'method' => 'external',
            'amount' => '1.0000',
            'currency' => 'EUR',
            'quantities' => '[]',
            'shipping' => 0,
            'restock' => 0,
            'reason' => '',
            'user_id' => null,
            'created_at' => '2026-10-30 12:00:00',
            'status' => 'done',
            'provider_reference' => $reference,
            'tax' => '0.0000',
            'taxes' => '[]',
        ]);

        return (int) $this->connection->lastInsertId();
    }
}
