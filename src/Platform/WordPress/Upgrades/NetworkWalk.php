<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Upgrades;

/**
 * How far the network got with bringing its shops up to a build: a network option, so no shop's own screens or
 * queue ever see the network's other sites. It goes through the sites in id order and keeps only the cursor and
 * the sites that failed, so it stays small on a network of thousands.
 */
final class NetworkWalk
{
    /**
     * @param array<int, array{error: string, attempts: int, retry_at: int}> $failed by site id
     */
    public function __construct(
        public readonly string $build,
        /** The network's sites when the walk began. */
        public readonly int $sites,
        /** The last site id gone through. */
        public int $cursor = 0,
        public int $checked = 0,
        /** The shops whose tables the walk brought up to the build. */
        public int $upgraded = 0,
        public array $failed = [],
        /** Every site was gone through once; what is left are the failures. */
        public bool $walked = false,
        /** The network admin was told it is done. */
        public bool $seen = false,
        public readonly int $at = 0,
    ) {}

    public static function fromOption(mixed $option): ?self
    {
        if (!\is_array($option) || !\is_string($option['build'] ?? null) || !\is_int($option['sites'] ?? null)) {
            return null;
        }
        $failed = [];
        foreach (\is_array($option['failed'] ?? null) ? $option['failed'] : [] as $site => $failure) {
            if (\is_int($site) && \is_array($failure)) {
                $failed[$site] = [
                    'error' => \is_string($failure['error'] ?? null) ? $failure['error'] : '',
                    'attempts' => \is_int($failure['attempts'] ?? null) ? $failure['attempts'] : 1,
                    'retry_at' => \is_int($failure['retry_at'] ?? null) ? $failure['retry_at'] : 0,
                ];
            }
        }

        return new self(
            $option['build'],
            $option['sites'],
            \is_int($option['cursor'] ?? null) ? $option['cursor'] : 0,
            \is_int($option['checked'] ?? null) ? $option['checked'] : 0,
            \is_int($option['upgraded'] ?? null) ? $option['upgraded'] : 0,
            $failed,
            ($option['walked'] ?? false) === true,
            ($option['seen'] ?? false) === true,
            \is_int($option['at'] ?? null) ? $option['at'] : 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'build' => $this->build,
            'sites' => $this->sites,
            'cursor' => $this->cursor,
            'checked' => $this->checked,
            'upgraded' => $this->upgraded,
            'failed' => $this->failed,
            'walked' => $this->walked,
            'seen' => $this->seen,
            'at' => $this->at,
        ];
    }

    /** Every shop is at the build. */
    public function finished(): bool
    {
        return $this->walked && $this->failed === [];
    }
}
