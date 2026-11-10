<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Security;

use OrillaCart\Shared\Security\LoggedText;
use OrillaCart\Shared\Security\Signatures;
use PHPUnit\Framework\TestCase;

final class LoggedTextTest extends TestCase
{
    public function testLineBreaksAndControlCharactersBecomeSpaces(): void
    {
        self::assertSame('a b c d [e f', LoggedText::oneLine("a\r\nb\nc\0d\x1B[e\u{2028}f"));
        self::assertSame('one two', LoggedText::oneLine("one\u{85}two"));
        self::assertSame('Ünïcödé stays', LoggedText::oneLine('Ünïcödé stays'));
    }

    public function testTextThatIsNotUtf8IsCleanedToo(): void
    {
        self::assertSame("caf\xE9 [OrillaCart] ERROR: made up", LoggedText::oneLine("caf\xE9\n[OrillaCart] ERROR: made up"));
    }

    public function testEmailsAndVatNumbersAreLeftOut(): void
    {
        self::assertSame(
            'The email to [email] did not go: SMTP said <[email]> is unknown',
            LoggedText::withoutPeople('The email to Jane.Doe+shop@example.co.uk did not go: SMTP said <jane.doe+shop@example.co.uk> is unknown'),
        );
        self::assertSame('[email]', LoggedText::withoutPeople('jürgen@müller.de'));
        self::assertSame('VIES did not check [VAT number]: MS_UNAVAILABLE', LoggedText::withoutPeople('VIES did not check DE123456789: MS_UNAVAILABLE'));
        self::assertSame('[VAT number], [VAT number] and [VAT number]', LoggedText::withoutPeople('NL123456789B01, FRXX123456789 and IE1234567WA'));
    }

    public function testWordsThatOnlyLookLikeVatNumbersStay(): void
    {
        self::assertSame('ESCALATIONS of DESCRIPTION12, order 1234567890, de12345678', LoggedText::withoutPeople('ESCALATIONS of DESCRIPTION12, order 1234567890, de12345678'));
        self::assertSame('Class@anonymous and user@localhost', LoggedText::withoutPeople('Class@anonymous and user@localhost'));
    }

    public function testTheEmailsATextMentionsAreFoundOnceEachInLowerCase(): void
    {
        self::assertSame(['jane@example.com', 'joe@example.org'], LoggedText::emails('Jane@Example.com, joe@example.org; again jane@example.com'));
        self::assertSame([], LoggedText::emails('nobody here'));
    }

    public function testAPersonsMarkIsTheSameHoweverTheirEmailIsWrittenAndTellsNothingWithoutTheKey(): void
    {
        $signatures = new Signatures('the site key');
        $mark = LoggedText::aboutMark($signatures, 'jane@example.com');

        self::assertNotNull($mark);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $mark);
        self::assertSame($mark, LoggedText::aboutMark($signatures, ' Jane@Example.COM '));
        self::assertNotSame($mark, LoggedText::aboutMark($signatures, 'joe@example.com'));
        self::assertNotSame($mark, LoggedText::aboutMark(new Signatures('another site'), 'jane@example.com'));
        self::assertNull(LoggedText::aboutMark(null, 'jane@example.com'));
        self::assertNull(LoggedText::aboutMark(new Signatures(static fn(): string => throw new \Error('Call to undefined function wp_salt()')), 'jane@example.com'));
    }
}
