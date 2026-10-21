<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application\Dto;

use OrillaCart\Audit\Domain\ChangeRecord;

final readonly class ChangeRecordView
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $user,
        public string $area,
        public string $route,
        public string $path,
        public array $values,
        public string $madeAt,
    ) {}

    public static function of(ChangeRecord $record): self
    {
        return new self($record->id(), $record->userId(), $record->userName(), $record->area(), $record->route(), $record->path(), $record->values(), $record->madeAt()->format(\DATE_ATOM));
    }
}
