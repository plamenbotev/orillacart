<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Pdf;

/**
 * The fonts a site names with the `orillacart_invoice_fonts` filter: a list of
 * ['family' => 'Noto Sans SC', 'normal' => '/path/NotoSansSC-Regular.ttf', 'bold' => '/path/NotoSansSC-Bold.ttf'],
 * TrueType files (.ttf: not .otf, nor .ttc collections) on the server; bold is optional.
 *
 *     add_filter('orillacart_invoice_fonts', fn(array $fonts): array => [...$fonts, [
 *         'family' => 'Noto Sans SC',
 *         'normal' => WP_CONTENT_DIR . '/fonts/NotoSansSC-Regular.ttf',
 *         'bold' => WP_CONTENT_DIR . '/fonts/NotoSansSC-Bold.ttf',
 *     ]]);
 */
final readonly class FilteredInvoiceFonts implements InvoiceFonts
{
    public const string FILTER = 'orillacart_invoice_fonts';
    private const string MISSING_OPTION = 'orillacart_invoice_cjk_without_font';

    public function cjk(): array
    {
        $named = \function_exists('apply_filters') ? apply_filters(self::FILTER, []) : [];
        $fonts = [];
        foreach (\is_array($named) ? $named : [] as $font) {
            if (!\is_array($font) || !\is_string($font['family'] ?? null) || preg_match('/^[\w \-]{1,60}$/u', $font['family']) !== 1) {
                continue;
            }
            $normal = self::file($font['normal'] ?? null);
            if ($normal !== null) {
                $fonts[] = ['family' => $font['family'], 'normal' => $normal, 'bold' => self::file($font['bold'] ?? null)];
            }
        }

        return $fonts;
    }

    public function printedCjk(bool $withFont): void
    {
        if (!\function_exists('get_option')) {
            return;
        }
        $since = get_option(self::MISSING_OPTION);
        if (!$withFont && $since === false) {
            update_option(self::MISSING_OPTION, gmdate(\DATE_ATOM), false);
        } elseif ($withFont && $since !== false) {
            delete_option(self::MISSING_OPTION);
        }
    }

    public function missingSince(): ?string
    {
        $since = \function_exists('get_option') ? get_option(self::MISSING_OPTION) : false;

        return \is_string($since) ? $since : null;
    }

    private static function file(mixed $path): ?string
    {
        if (!\is_string($path) || strtolower(pathinfo($path, \PATHINFO_EXTENSION)) !== 'ttf' || !is_readable($path)) {
            return null;
        }
        $real = realpath($path);

        return $real === false ? null : $real;
    }
}
