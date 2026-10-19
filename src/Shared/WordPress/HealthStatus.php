<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * How a Site Health check found the shop: good, worth a look (recommended), or in need of fixing (critical).
 */
final readonly class HealthStatus
{
    public const string GOOD = 'good';
    public const string RECOMMENDED = 'recommended';
    public const string CRITICAL = 'critical';

    /**
     * @param string $label       the finding, as a heading: "Nothing runs the background jobs"
     * @param string $description what it means and what to do, as plain text
     * @param string $actionUrl   where to fix it, such as the shop's Background jobs screen; empty for nowhere
     */
    public function __construct(
        public string $status,
        public string $label,
        public string $description,
        public string $actionUrl = '',
        public string $actionLabel = '',
    ) {}

    public static function good(string $label, string $description = ''): self
    {
        return new self(self::GOOD, $label, $description);
    }

    public static function recommended(string $label, string $description, string $actionUrl = '', string $actionLabel = ''): self
    {
        return new self(self::RECOMMENDED, $label, $description, $actionUrl, $actionLabel);
    }

    public static function critical(string $label, string $description, string $actionUrl = '', string $actionLabel = ''): self
    {
        return new self(self::CRITICAL, $label, $description, $actionUrl, $actionLabel);
    }
}
