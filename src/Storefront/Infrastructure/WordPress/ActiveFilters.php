<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Search\Domain\Facets;

/**
 * The choices narrowing a listing, each with the address that drops it: the words searched, the category and
 * tag the shopper chose (not those the page fixes), the brands and the shop's filters' values, the price range
 * and stock. The labels are the filter panel's, in the shopper's language; the template words each kind. The
 * addresses are the listing's first page, keeping everything else (sort, view), with lists written as the
 * forms send them (brand[]=3).
 *
 * @phpstan-import-type Choices from ShopListing
 * @phpstan-import-type Panel from ShopListing
 *
 * @phpstan-type Chip array{kind: string, group: string, label: string, min: ?string, max: ?string, url: string}
 */
final class ActiveFilters
{
    /**
     * @param Choices                              $choices
     * @param Panel                                $panel   the brands' and filters' values with their labels
     * @param string                               $url     the listing's first page with the shopper's choices
     * @param array{category: string, tag: string} $names   the names of the category and tag the shopper chose; '' for none or one the page fixes
     * @param ?int                                 $brand   a brand the page fixes, not the shopper's to drop
     *
     * @return list<Chip>
     */
    public static function chips(array $choices, array $panel, string $url, array $names, ?int $brand = null): array
    {
        $chips = [];
        $chip = static fn(string $kind, string $group, string $label, string $url, ?string $min = null, ?string $max = null): array => compact('kind', 'group', 'label', 'min', 'max', 'url');
        if ($choices['search'] !== '') {
            // Best match goes with the words.
            $chips[] = $chip('search', '', $choices['search'], self::without($url, ['q' => null, 'sort' => $choices['sort'] === 'relevance' ? null : false]));
        }
        foreach (['category', 'tag'] as $argument) {
            if ($names[$argument] !== '') {
                $chips[] = $chip($argument, '', $names[$argument], self::without($url, [$argument => null]));
            }
        }
        foreach ($panel['filters'] as $filter) {
            $argument = substr($filter['name'], 0, -2);
            foreach ($filter['values'] as $value) {
                if ($value['checked'] && !($argument === 'brand' && $value['value'] === (string) $brand)) {
                    $chips[] = $chip('filter', $filter['label'], $value['label'], self::without($url, [$argument => $value['value']]));
                }
            }
        }
        if ($choices['price_min'] !== null || $choices['price_max'] !== null) {
            $chips[] = $chip('price', '', '', self::without($url, ['price_min' => null, 'price_max' => null]), $choices['price_min'], $choices['price_max']);
        }
        if ($choices['in_stock']) {
            $chips[] = $chip('in_stock', '', '', self::without($url, ['in_stock' => null]));
        }

        return $chips;
    }

    /**
     * The address without all of the choices the shopper may drop.
     *
     * @param bool $terms the category and tag are the shopper's (not the page's)
     */
    public static function clearUrl(string $url, bool $terms): string
    {
        $drop = array_fill_keys(['q', 'brand', 'f', 'price_min', 'price_max', 'in_stock', ...$terms ? ['category', 'tag'] : []], null);

        return self::without($url, $drop + ['sort' => 'relevance']);
    }

    /**
     * The address without some of its query's arguments.
     *
     * @param array<string, string|false|null> $drop by argument (f[size] for one of the shop's filters): null drops it whole,
     *                                              a string one of its values (or the argument holding that value), false nothing
     */
    public static function without(string $url, array $drop): string
    {
        $at = strpos($url, '?');
        if ($at === false) {
            return $url;
        }
        $fragment = strpos($url, '#', $at);
        $query = [];
        parse_str(substr($url, $at + 1, $fragment === false ? null : $fragment - $at - 1), $query);
        foreach ($drop as $argument => $value) {
            if ($value === false) {
                continue;
            }
            // f[size] is the size list inside f.
            $query = preg_match('/^([^\[]+)\[([^\]]+)\]$/', $argument, $parts) === 1 ? self::drop($query, $parts[1], [$parts[2]], $value) : self::drop($query, $argument, [], $value);
        }
        $string = http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        // A list's values as the forms send them: brand[]=3, not brand[0]=3.
        $string = (string) preg_replace('/%5B\d+%5D=/', '%5B%5D=', $string);

        return substr($url, 0, $at) . ($string === '' ? '' : '?' . $string) . ($fragment === false ? '' : substr($url, $fragment));
    }

    /**
     * @param array<mixed> $query
     * @param list<string> $inner the keys inside it, to the list that holds the value
     *
     * @return array<mixed>
     */
    private static function drop(array $query, string $key, array $inner, ?string $value): array
    {
        if (!\array_key_exists($key, $query)) {
            return $query;
        }
        if ($inner !== []) {
            if (\is_array($query[$key])) {
                $query[$key] = self::drop($query[$key], $inner[0], \array_slice($inner, 1), $value);
                if ($query[$key] === []) {
                    unset($query[$key]);
                }
            }

            return $query;
        }
        if ($value === null) {
            unset($query[$key]);
        } elseif (\is_array($query[$key])) {
            $query[$key] = array_values(array_filter($query[$key], static fn(mixed $given): bool => !self::same($given, $value)));
            if ($query[$key] === []) {
                unset($query[$key]);
            }
        } elseif (self::same($query[$key], $value)) {
            unset($query[$key]);
        }

        return $query;
    }

    /** The address's value is the one named, as the index matches them: "Size M" is "size m". */
    private static function same(mixed $given, string $value): bool
    {
        return \is_string($given) && Facets::value($given) === Facets::value($value);
    }
}
