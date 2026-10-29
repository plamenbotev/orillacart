<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Forms;

use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Translations\Domain\TranslationRuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: a form's labels, help, placeholders and choices in the site's other languages, saved with the form.
 */
final class FormTranslationsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
    }

    public function testANewFieldIsTranslatedInTheSaveThatMakesItsKey(): void
    {
        $form = $this->shop->forms->create($this->engraving(
            ['label' => ['de_DE' => 'Gravur'], 'help' => ['de_DE' => 'Bis zu 20 Zeichen.']],
            ['de_DE' => 'Gold'],
        ));

        self::assertSame('engraving', $form->fields[0]['key']);
        self::assertSame(['label' => ['de_DE' => 'Gravur'], 'help' => ['de_DE' => 'Bis zu 20 Zeichen.']], $form->fields[0]['translations']);
        $choices = $form->fields[1]['choices'];
        self::assertIsArray($choices);
        self::assertSame([['de_DE' => 'Gold'], []], array_column($choices, 'translations'));
    }

    public function testTheShopperIsAskedInTheLanguageShown(): void
    {
        $this->shop->forms->create($this->engraving(['label' => ['de_DE' => 'Gravur']], ['de_DE' => 'Golden']));

        $this->shop->languageOfRequest->locale = 'de_DE';
        $fields = $this->shop->shopForms->productFields(10, []);

        self::assertSame(['Gravur', 'Up to 20 characters.'], [$fields[0]->label, $fields[0]->help], 'help without a translation keeps its own');
        self::assertSame(['Golden', 'Silver'], array_column($fields[1]->choices, 'label'));
        self::assertSame(['gold', 'silver'], array_column($fields[1]->choices, 'value'), 'answers are still checked by value');
    }

    public function testRemovedFieldsAndChoicesLoseTheirWordsAndASaveWithoutThemKeepsThem(): void
    {
        $form = $this->shop->forms->create($this->engraving(['label' => ['de_DE' => 'Gravur']], ['de_DE' => 'Golden']));

        // The colour keeps only silver, sent without translations; the engraving goes.
        $after = $this->shop->forms->update($form->id, new SaveForm('Personalise', 'product', fields: [
            new SaveFormField('Colour', 'choice', key: 'colour', choices: [new SaveFieldChoice('Silver', 'silver', translations: ['de_DE' => 'Silber'])]),
        ]));

        self::assertSame(['field.colour.choice.silver'], array_keys($this->shop->translationRecords->all('form', [$form->id])[$form->id]));
        self::assertSame([['value' => 'silver', 'label' => 'Silver', 'price' => null, 'translations' => ['de_DE' => 'Silber']]], $after->fields[0]['choices']);

        $this->shop->forms->delete($form->id);
        self::assertSame([], $this->shop->translationRecords->all('form', [$form->id]));
    }

    public function testATranslationTooLongRefusesTheSave(): void
    {
        $this->expectException(TranslationRuleViolation::class);

        $this->shop->forms->create($this->engraving(['label' => ['de_DE' => str_repeat('G', 101)]], null));
    }

    /**
     * @param array<string, array<string, string>>|null $engraving
     * @param array<string, string>|null                $gold
     */
    private function engraving(?array $engraving, ?array $gold): SaveForm
    {
        return new SaveForm('Personalise', 'product', fields: [
            new SaveFormField('Engraving', help: 'Up to 20 characters.', translations: $engraving),
            new SaveFormField('Colour', 'choice', choices: [new SaveFieldChoice('Gold', translations: $gold), new SaveFieldChoice('Silver')]),
        ]);
    }
}
