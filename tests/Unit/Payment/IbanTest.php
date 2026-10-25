<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Payment\Domain\Iban;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase
{
    #[TestWith(['DE89 3704 0044 0532 0130 00', 'DE89370400440532013000'])]
    #[TestWith(['gb82 west 1234 5698 7654 32', 'GB82WEST12345698765432'])]
    #[TestWith(['BG80BNBG96611020345678', 'BG80BNBG96611020345678'])]
    public function testAnIbanWhoseCheckDigitsAddUpIsKeptInCapitals(string $entered, string $kept): void
    {
        self::assertSame($kept, Iban::check($entered));
    }

    #[TestWith(['DE89 3704 0044 0532 0130 01'])]
    #[TestWith(['DE89'])]
    #[TestWith(['not an iban'])]
    public function testOneThatDoesNotIsNot(string $entered): void
    {
        self::assertNull(Iban::check($entered));
    }

    public function testItIsWrittenInGroupsOfFour(): void
    {
        self::assertSame('DE89 3704 0044 0532 0130 00', Iban::grouped('DE89370400440532013000'));
    }
}
