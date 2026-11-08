<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Packages\Application\RunLog;
use OrillaCart\Packages\Domain\ExtensionRun;

final readonly class DoctrineRunLog implements RunLog
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function record(string $extension, string $what, string $outcome, ?string $message, array $used, \DateTimeImmutable $at): void
    {
        $run = new ExtensionRun($extension, mb_substr($what, 0, ExtensionRun::MAX_WHAT_LENGTH), $outcome, $message === null ? null : mb_substr($message, 0, 2000), $used, $at);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        // Now and then, the rows past the extension's share go; the retention task takes the old ones by date.
        if (random_int(1, 25) === 1) {
            $this->trim($extension);
        }
    }

    public function recent(string $extension, int $limit = 50): array
    {
        /** @var list<ExtensionRun> */
        return $this->entityManager->getRepository(ExtensionRun::class)->findBy(['extension' => $extension], ['id' => 'DESC'], max(1, min($limit, 200)));
    }

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionRun::class . ' r WHERE r.ranAt < :before')->setParameter('before', $before)->execute();
    }

    public function forgetExtension(string $extension): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionRun::class . ' r WHERE r.extension = :e')->setParameter('e', $extension)->execute();
    }

    private function trim(string $extension): void
    {
        $kept = $this->entityManager->getRepository(ExtensionRun::class)->findBy(['extension' => $extension], ['id' => 'DESC'], 1, self::KEPT - 1);
        $oldest = $kept[0] ?? null;
        if ($oldest instanceof ExtensionRun) {
            $this->entityManager->createQuery('DELETE FROM ' . ExtensionRun::class . ' r WHERE r.extension = :e AND r.id < :id')->setParameters(['e' => $extension, 'id' => $oldest->id()])->execute();
        }
    }
}
