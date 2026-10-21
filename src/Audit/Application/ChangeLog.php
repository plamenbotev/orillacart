<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application;

use OrillaCart\Audit\Application\Dto\ChangeLogPageView;
use OrillaCart\Audit\Application\Dto\ChangeLogQuery;
use OrillaCart\Audit\Application\Dto\ChangeRecordView;
use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Audit\Domain\ChangeRecordRepository;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;

/**
 * The shop's change log: each change a shop manager made through the shop's API, kept for KEEP_DAYS. What was
 * sent is kept as sent, but for secrets (passwords, keys, tokens), which are blanked out.
 */
final readonly class ChangeLog
{
    public const int KEEP_DAYS = 365;
    /** Keys whose values are never kept: "client_secret", "password", "api_key", "token", "key". */
    private const string SECRET = '/(secret|password|passwd|token|api_?key|private)|^key$/i';
    private const int MOST_BYTES = 8000;

    public function __construct(
        private ChangeRecordRepository $records,
        private UserNames $users,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $values what was sent
     */
    public function record(?int $userId, string $method, string $route, string $path, array $values): void
    {
        $values = self::blank($values);
        $encoded = json_encode($values);
        if ($encoded === false || \strlen($encoded) > self::MOST_BYTES) {
            $values = ['_' => 'Too long to keep.'];
        }
        $name = $userId === null ? '' : ($this->users->names([$userId])[$userId] ?? '');
        $area = explode('/', trim($route, '/'))[0] ?? '';
        $this->records->save(new ChangeRecord($userId, mb_substr($name, 0, 100), mb_substr($area, 0, 64), mb_substr("{$method} {$route}", 0, 200), mb_substr($path, 0, 255), $values, $this->clock->now()));
    }

    public function page(ChangeLogQuery $query): ChangeLogPageView
    {
        [$records, $total] = $this->records->search($query->area, $query->userId, ($query->page - 1) * $query->perPage, $query->perPage);

        return new ChangeLogPageView(
            array_map(ChangeRecordView::of(...), $records),
            $total,
            $query->page,
            max(1, (int) ceil($total / $query->perPage)),
            $this->records->areas(),
        );
    }

    /** @return int how many records older than KEEP_DAYS went */
    public function forgetOld(): int
    {
        return $this->records->removeOlderThan($this->clock->now()->modify('-' . self::KEEP_DAYS . ' days'));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function blank(array $values): array
    {
        $kept = [];
        foreach ($values as $key => $value) {
            $kept[(string) $key] = preg_match(self::SECRET, (string) $key) === 1 && $value !== '' && $value !== null
                ? '••••'
                : (\is_array($value) ? self::blank($value) : $value);
        }

        return $kept;
    }
}
