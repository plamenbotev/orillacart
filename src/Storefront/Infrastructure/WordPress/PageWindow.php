<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

/**
 * Which pages a list of many links to: the first, the last, and two each side of the one shown, a gap (null)
 * where pages are left out between them. A shop of ten thousand products links to seven pages, not 834.
 */
final class PageWindow
{
    private const int AROUND = 2;

    /**
     * @param \Closure(int): string $url a page's link
     *
     * @return list<array{number: ?int, url: string}> number null for a gap
     */
    public static function links(int $page, int $pages, \Closure $url): array
    {
        $links = [];
        $previous = 0;
        foreach (self::numbers($page, $pages) as $number) {
            if ($number > $previous + 1) {
                $links[] = ['number' => null, 'url' => ''];
            }
            $links[] = ['number' => $number, 'url' => $url($number)];
            $previous = $number;
        }

        return $links;
    }

    /**
     * @return list<int>
     */
    public static function numbers(int $page, int $pages): array
    {
        if ($pages < 1) {
            return [];
        }
        $page = max(1, min($pages, $page));
        $numbers = [1 => true, $pages => true];
        for ($number = max(1, $page - self::AROUND); $number <= min($pages, $page + self::AROUND); ++$number) {
            $numbers[$number] = true;
        }
        // A gap of one page is that page: a link is no wider than the dots.
        if (isset($numbers[3]) && !isset($numbers[2])) {
            $numbers[2] = true;
        }
        if (isset($numbers[$pages - 2]) && !isset($numbers[$pages - 1])) {
            $numbers[$pages - 1] = true;
        }
        $numbers = array_keys($numbers);
        sort($numbers);

        return $numbers;
    }
}
