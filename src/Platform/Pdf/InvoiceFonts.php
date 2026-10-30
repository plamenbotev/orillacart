<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Pdf;

/**
 * The fonts invoice PDFs print Chinese, Japanese and Korean letters in, which DejaVu Sans has none of, as the site
 * names them; and whether the last page with such letters had none to print them in, for Site Health.
 */
interface InvoiceFonts
{
    /**
     * Those whose TrueType files are there, in the order named.
     *
     * @return list<array{family: string, normal: string, bold: string|null}>
     */
    public function cjk(): array;

    /** A page with CJK letters printed: with a font for them, or without one. */
    public function printedCjk(bool $withFont): void;

    /** When a page with CJK letters last printed without a font for them; null when none did since one was named. */
    public function missingSince(): ?string;
}
