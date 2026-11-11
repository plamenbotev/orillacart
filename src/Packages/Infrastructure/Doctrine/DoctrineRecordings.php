<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Packages\Application\Recordings;
use OrillaCart\Packages\Domain\ExtensionRecording;

final readonly class DoctrineRecordings implements Recordings
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(ExtensionRecording $recording): void
    {
        $this->entityManager->persist($recording);
        $this->entityManager->flush();
        // Each save lets the old ones go: past their day, and past the extension's share.
        $this->forgetBefore($recording->recordedAt()->sub(new \DateInterval('PT' . self::KEPT_HOURS . 'H')));
        $kept = $this->entityManager->getRepository(ExtensionRecording::class)->findBy(['extension' => $recording->extension()], ['id' => 'DESC'], 1, self::KEPT - 1);
        $oldest = $kept[0] ?? null;
        if ($oldest instanceof ExtensionRecording) {
            $this->entityManager->createQuery('DELETE FROM ' . ExtensionRecording::class . ' r WHERE r.extension = :e AND r.id < :id')->setParameters(['e' => $recording->extension(), 'id' => $oldest->id()])->execute();
        }
    }

    public function recent(string $extension): array
    {
        /** @var list<ExtensionRecording> */
        return $this->entityManager->getRepository(ExtensionRecording::class)->findBy(['extension' => $extension], ['id' => 'DESC'], self::KEPT);
    }

    public function find(string $extension, int $id): ?ExtensionRecording
    {
        $recording = $this->entityManager->getRepository(ExtensionRecording::class)->findOneBy(['extension' => $extension, 'id' => $id]);

        return $recording instanceof ExtensionRecording ? $recording : null;
    }

    public function delete(ExtensionRecording $recording): void
    {
        $this->entityManager->remove($recording);
        $this->entityManager->flush();
    }

    public function forgetBefore(\DateTimeImmutable $before): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionRecording::class . ' r WHERE r.recordedAt < :before')->setParameter('before', $before)->execute();
    }

    public function forgetExtension(string $extension): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . ExtensionRecording::class . ' r WHERE r.extension = :e')->setParameter('e', $extension)->execute();
    }
}
