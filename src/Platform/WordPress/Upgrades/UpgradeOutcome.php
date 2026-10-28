<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

/**
 * How bringing one site's database up to the files' build went.
 */
final readonly class UpgradeOutcome
{
    private const string CURRENT = 'current';
    private const string UPGRADED = 'upgraded';
    private const string BUSY = 'busy';
    private const string PAUSED = 'paused';
    private const string FAILED = 'failed';
    private const string NO_SHOP = 'no_shop';

    /**
     * @param list<string> $migrations the migrations that ran
     */
    private function __construct(
        public string $kind,
        public array $migrations = [],
        public string $error = '',
    ) {}

    public static function current(): self
    {
        return new self(self::CURRENT);
    }

    /**
     * @param list<string> $migrations
     */
    public static function upgraded(array $migrations): self
    {
        return new self(self::UPGRADED, $migrations);
    }

    /** Another request is upgrading the site. */
    public static function busy(): self
    {
        return new self(self::BUSY);
    }

    /** The last try failed a short while ago; the site is left alone for a while. */
    public static function paused(string $error): self
    {
        return new self(self::PAUSED, [], $error);
    }

    public static function failed(string $error): self
    {
        return new self(self::FAILED, [], $error);
    }

    /** The site does not run the shop, or is gone. */
    public static function noShop(): self
    {
        return new self(self::NO_SHOP);
    }

    public function isCurrent(): bool
    {
        return $this->kind === self::CURRENT;
    }

    public function isUpgraded(): bool
    {
        return $this->kind === self::UPGRADED;
    }

    public function isBusy(): bool
    {
        return $this->kind === self::BUSY;
    }

    public function isPaused(): bool
    {
        return $this->kind === self::PAUSED;
    }

    public function isFailed(): bool
    {
        return $this->kind === self::FAILED;
    }

    public function isNoShop(): bool
    {
        return $this->kind === self::NO_SHOP;
    }

    /** Whether the site's tables are at the files' build now. */
    public function isUpToDate(): bool
    {
        return $this->isCurrent() || $this->isUpgraded();
    }
}
