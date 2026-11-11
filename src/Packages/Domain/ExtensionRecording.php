<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A recording of one run of an extension for its debugger (design 71): what ran, how it ended, the files it ran
 * and its steps. Kept a day at most (Recordings::KEPT_HOURS): it holds what the run saw.
 */
#[ORM\Entity]
#[ORM\Table(name: 'extension_recordings')]
#[ORM\Index(name: 'extension_recording_recent', columns: ['extension', 'id'])]
#[ORM\Index(name: 'extension_recording_at', columns: ['recorded_at'])]
class ExtensionRecording
{
    public const string TEST = 'test';
    public const string CONSOLE = 'console';
    public const string LIVE = 'live';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $data the recording: its sources, steps and the stack where it failed
     */
    public function __construct(
        #[ORM\Column(length: 40)]
        private string $extension,
        #[ORM\Column(length: 20)]
        private string $source,
        #[ORM\Column(length: ExtensionRun::MAX_WHAT_LENGTH)]
        private string $what,
        #[ORM\Column(length: 20)]
        private string $outcome,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $message,
        #[ORM\Column(type: 'json')]
        private array $data,
        #[ORM\Column]
        private \DateTimeImmutable $recordedAt,
    ) {}

    public function id(): int
    {
        return $this->id ?? 0;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    /** test, console or live: what made it */
    public function source(): string
    {
        return $this->source;
    }

    public function what(): string
    {
        return $this->what;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
