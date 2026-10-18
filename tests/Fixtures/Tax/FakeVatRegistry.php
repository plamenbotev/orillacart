<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Tax;

use OrillaCart\Tax\Application\Dto\VatCheck;
use OrillaCart\Tax\Application\VatRegistry;

/**
 * VIES as a test wants it: the numbers it knows, and whether it answers at all.
 */
final class FakeVatRegistry implements VatRegistry
{
    /** @var array<string, string> registered names, by number with prefix */
    public array $known = [];
    public bool $answers = true;
    /** @var list<string> the numbers asked about */
    public array $asked = [];

    public function check(string $prefix, string $number): VatCheck
    {
        $this->asked[] = $prefix . $number;
        if (!$this->answers) {
            return new VatCheck(VatCheck::UNAVAILABLE, $prefix . $number);
        }

        return isset($this->known[$prefix . $number])
            ? new VatCheck(VatCheck::VALID, $prefix . $number, $this->known[$prefix . $number])
            : new VatCheck(VatCheck::INVALID, $prefix . $number);
    }
}
