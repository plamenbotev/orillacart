<?php

declare(strict_types=1);

namespace OrillaCart\Audit\Application;

use OrillaCart\Audit\Application\Dto\ChangeLogPageView;
use OrillaCart\Audit\Application\Dto\ChangeLogQuery;
use OrillaCart\Audit\Application\Dto\ChangeRecordView;
use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Audit\Domain\ChangeRecordRepository;
use OrillaCart\Shared\Security\SecretKeys;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;

/**
 * The shop's change log: each change a shop manager made through the shop's API, kept for KEEP_DAYS. What was
 * sent is kept as sent, but for secrets, which are blanked out: settings declared secret by the gateway, shipping
 * method or extension the change was for, keys named as secrets (SecretKeys), and in a map of `settings` every
 * setting the request did not declare plain, as one not declared could be a secret.
 */
final readonly class ChangeLog
{
    public const int KEEP_DAYS = 365;
    private const int MOST_BYTES = 8000;

    public function __construct(
        private ChangeRecordRepository $records,
        private UserNames $users,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $values  what was sent
     * @param string|null             $via     "assistant" or "api_key" when the change did not come from the admin's screens
     * @param string|null             $keyName the API key it came with
     */
    public function record(?int $userId, string $method, string $route, string $path, array $values, ?string $via = null, ?string $keyName = null): void
    {
        $values = self::blank($values);
        $encoded = json_encode($values);
        if ($encoded === false || \strlen($encoded) > self::MOST_BYTES) {
            $values = ['_' => 'Too long to keep.'];
        }
        $name = $userId === null ? '' : ($this->users->names([$userId])[$userId] ?? '');
        $area = explode('/', trim($route, '/'))[0] ?? '';
        $this->records->save(new ChangeRecord($userId, mb_substr($name, 0, 100), mb_substr($area, 0, 64), mb_substr("{$method} {$route}", 0, 200), mb_substr($path, 0, 255), $values, $this->clock->now(), $via, $keyName === null ? null : mb_substr($keyName, 0, 100)));
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

    /**
     * A privacy request erased someone: what was sent about them goes from the records made on their order or
     * account, and from those that mention their email. Who changed what, and when, stays.
     *
     * @param list<string> $paths the routes of what was theirs: "/orders/12", "/customers/3"
     *
     * @return int how many records were changed
     */
    public function forget(array $paths, ?string $email = null): int
    {
        return $this->records->replaceValues($paths, $email, ['_' => 'Erased, as a privacy request asked.']);
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
    private static function blank(array $values, bool $settings = false): array
    {
        $kept = [];
        foreach ($values as $key => $value) {
            $secret = $settings ? SecretKeys::isSecretSetting((string) $key) : SecretKeys::isSecret((string) $key);
            $kept[(string) $key] = $secret && $value !== '' && $value !== null && $value !== false
                ? '••••'
                : (\is_array($value) ? self::blank($value, !$settings && $key === 'settings') : $value);
        }

        return $kept;
    }
}
