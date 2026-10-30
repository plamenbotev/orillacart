<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Platform\Pdf\InvoiceFonts;

/** The CJK fonts a test names, and what the printer told of them. */
final class FixedInvoiceFonts implements InvoiceFonts
{
    /** @var list<bool> each page with CJK letters: whether it had a font for them */
    public array $printed = [];

    /**
     * @param list<array{family: string, normal: string, bold: string|null}> $fonts
     */
    public function __construct(public array $fonts = []) {}

    public function cjk(): array
    {
        return $this->fonts;
    }

    public function printedCjk(bool $withFont): void
    {
        $this->printed[] = $withFont;
    }

    public function missingSince(): ?string
    {
        return \in_array(false, $this->printed, true) ? '2026-10-30T00:00:00+00:00' : null;
    }
}
