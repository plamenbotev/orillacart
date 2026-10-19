<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something Tools → Site Health checks about the shop, such as whether anything runs its background jobs, and
 * what Site Health → Info lists about it. Built only when Site Health asks.
 */
#[AutoconfigureTag(self::TAG)]
interface HealthCheck
{
    public const string TAG = 'orillacart.health_check';

    /** Unique, lowercase letters and underscores: "jobs". */
    public static function id(): string;

    /** What Site Health lists while it runs the check: "Background jobs". */
    public static function label(): string;

    public function check(): HealthStatus;

    /**
     * @return array<string, array{label: string, value: string}> by key, for Site Health → Info under OrillaCart
     */
    public function info(): array;
}
