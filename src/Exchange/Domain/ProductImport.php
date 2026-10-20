<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A products file being imported: where it is kept, the columns its header names, how far a job has read it, and
 * what came of its rows. A row that is refused is reported with its number and why; the others go on.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_imports')]
class ProductImport
{
    /** The rows' messages kept: enough to fix a file, not a log of every row of a broken one. */
    public const int MAX_ERRORS = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Ready;

    /** Where in the file the next row starts, in bytes. */
    #[ORM\Column(type: 'bigint')]
    private int $position;

    #[ORM\Column]
    private int $done = 0;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $updated = 0;

    #[ORM\Column]
    private int $failed = 0;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $errors = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param list<string> $columns the header's column ids, in the file's order
     */
    public function __construct(
        #[ORM\Column(length: 100)]
        private string $fileKey,
        #[ORM\Column(length: 255)]
        private string $filename,
        #[ORM\Column(type: 'json')]
        private array $columns,
        #[ORM\Column(name: 'row_count')]
        private int $rows,
        int $position,
        #[ORM\Column(nullable: true)]
        private ?int $userId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->position = $position;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The import has no id until it is saved.');
    }

    public function status(): ImportStatus
    {
        return $this->status;
    }

    public function fileKey(): string
    {
        return $this->fileKey;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function done(): int
    {
        return $this->done;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @throws ExchangeRuleViolation once it started
     */
    public function start(): void
    {
        if ($this->status !== ImportStatus::Ready) {
            throw new ExchangeRuleViolation('This import has started already.', 'orillacart_import_started');
        }
        $this->status = ImportStatus::Running;
    }

    public function createdRow(): void
    {
        ++$this->done;
        ++$this->created;
    }

    public function updatedRow(): void
    {
        ++$this->done;
        ++$this->updated;
    }

    /** A row that was refused, by its line in the file (the header is line 1). */
    public function failedRow(int $line, string $why): void
    {
        ++$this->done;
        ++$this->failed;
        if (\count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = \sprintf('Row %d: %s', $line, $why);
        }
    }

    /** A batch ended here in the file. */
    public function reached(int $position): void
    {
        $this->position = $position;
    }

    public function finish(\DateTimeImmutable $at): void
    {
        $this->status = ImportStatus::Done;
        $this->finishedAt = $at;
    }
}
