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
        $this->connection->executeStatement("DELETE FROM {$this->table} WHERE provider_reference = 'TEST-REF-239'");
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

    private function insert(string $gateway, string $reference): void
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
    }
}
