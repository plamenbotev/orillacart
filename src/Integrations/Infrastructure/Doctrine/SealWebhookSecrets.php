<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Integrations\Domain\Webhook;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Security\Secrets;

/**
 * After an update, seals the signing secrets saved before secrets were sealed: the column reads either, but a copy of
 * the database would give the old ones away.
 */
final readonly class SealWebhookSecrets implements LifecycleListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Secrets $secrets,
    ) {}

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void
    {
        $connection = $this->entityManager->getConnection();
        $table = $this->entityManager->getClassMetadata(Webhook::class)->getTableName();
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return;
        }
        $rows = $connection->fetchAllKeyValue("SELECT id, secret FROM {$table}");
        foreach ($rows as $id => $secret) {
            if (\is_string($secret) && !Secrets::isSealed($secret)) {
                $connection->update($table, ['secret' => $this->secrets->seal($secret)], ['id' => $id]);
            }
        }
    }

    public function deactivate(): void {}

    public function uninstall(): void {}
}
