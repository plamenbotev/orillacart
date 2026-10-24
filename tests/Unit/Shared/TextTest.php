<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared;

use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testAViolationKeepsItsWordsAndValuesApartAndReadsAsTheEnglishSentence(): void
    {
        $refused = new RuleViolation('Only %1$d of %2$s are in stock.', 'orillacart_insufficient_stock', values: [2, new Text('the lamp')]);

        self::assertSame('Only %1$d of %2$s are in stock.', $refused->text->template);
        self::assertSame('Only 2 of the lamp are in stock.', $refused->getMessage());
        self::assertSame('Row 3: Only 2 of the lamp are in stock.', (string) Text::of('Row %1$d: %2$s', 3, $refused->text), 'words within words');
    }

    public function testATranslationThatLostAPlaceholderShowsWithoutTheValuesRatherThanFailing(): void
    {
        self::assertSame('Only %1$d left.', Text::format('Only %1$d left.', []));
        self::assertSame('Само %1$d от %3$s.', Text::format('Само %1$d от %3$s.', [2, 'лампа']));
    }

    public function testWithoutWordPressTheEnglishStaysAndValidationParametersGoIn(): void
    {
        $translations = new GettextTranslations();

        self::assertSame('Up to 3 characters.', $translations->text(Text::of('Up to %d characters.', 3)));
        self::assertSame('This value is too long. It should have 40 characters or less.', $translations->message('This value is too long. It should have {{ limit }} character or less.|This value is too long. It should have {{ limit }} characters or less.', ['{{ limit }}' => 40], 40));
    }
}
