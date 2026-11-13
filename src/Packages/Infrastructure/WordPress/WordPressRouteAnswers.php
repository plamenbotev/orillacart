<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\RouteAnswers;
use OrillaCart\Search\Infrastructure\WordPress\IndexChanges;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * Route answers in the site's persistent object cache where it has one, shared by every request; in transients
 * otherwise, which WordPress removes once they are old. The search index's generation is part of the key, so a
 * product changed is a new answer the next time, whatever the seconds left.
 */
final readonly class WordPressRouteAnswers implements RouteAnswers
{
    private const string GROUP = 'orillacart_route_answers';

    public function __construct(private Currencies $currencies, private ContentLanguages $languages) {}

    public function get(string $scope, string $path, array $query): ?array
    {
        $key = $this->key($scope, $path, $query);
        $known = wp_using_ext_object_cache() ? wp_cache_get($key, self::GROUP) : get_transient($key);
        if (!\is_array($known) || !\is_int($known['status'] ?? null) || !\is_array($known['body'] ?? null)) {
            return null;
        }
        $body = [];
        foreach ($known['body'] as $name => $value) {
            $body[(string) $name] = $value;
        }

        return ['status' => $known['status'], 'body' => $body];
    }

    public function set(string $scope, string $path, array $query, array $answer, int $seconds): void
    {
        $key = $this->key($scope, $path, $query);
        if (wp_using_ext_object_cache()) {
            wp_cache_set($key, $answer, self::GROUP, $seconds);
        } else {
            set_transient($key, $answer, $seconds);
        }
    }

    /** @param array<array-key, mixed> $query */
    private function key(string $scope, string $path, array $query): string
    {
        self::sort($query);

        return 'orillacart_route_' . md5(serialize([$scope, $path, $query, $this->languages->current()->locale ?? determine_locale(), $this->currencies->current(), IndexChanges::generation()]));
    }

    /**
     * The same choices in another order are the same request.
     *
     * @param array<array-key, mixed> $query
     */
    private static function sort(array &$query): void
    {
        array_is_list($query) ? sort($query) : ksort($query);
        foreach ($query as &$value) {
            if (\is_array($value)) {
                self::sort($value);
            }
        }
    }
}
