<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Forms;

use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveFieldCondition;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Forms\Application\FieldAnswers;
use OrillaCart\Forms\Application\FormService;
use OrillaCart\Forms\Application\FormTexts;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Forms\Domain\FormNotFound;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Forms\InMemoryFormRepository;
use OrillaCart\Tests\Fixtures\Forms\InMemoryFormUploadRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;
use PHPUnit\Framework\TestCase;

final class FormServiceTest extends TestCase
{
    private InMemoryFormRepository $repository;
    private FormService $forms;
    private ShopForms $shop;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFormRepository();
        $shop = new TestShop();
        $texts = new FormTexts($shop->contentTranslations);
        $this->forms = new FormService($this->repository, $shop->productTranslations, $texts, new ImmediateTransactions());
        $this->shop = new ShopForms($this->repository, new FieldAnswers(new InMemoryFormUploadRepository()), $texts);
    }

    public function testKeysAndValuesAreMadeFromLabelsOnceAndKept(): void
    {
        $form = $this->forms->create(new SaveForm('Engraving', fields: [
            new SaveFormField('Engraving text'),
            new SaveFormField('Engraving text!', 'choice', choices: [new SaveFieldChoice('Gold'), new SaveFieldChoice('Gold'), new SaveFieldChoice('12 ct', price: '4.50')]),
            new SaveFormField('Гравиране'),
        ]));

        // Transliterated where the site has intl.
        self::assertSame(['engraving_text', 'engraving_text_2', class_exists(\Transliterator::class) ? 'gravirane' : 'field'], array_column($form->fields, 'key'));
        self::assertSame([['value' => 'gold', 'label' => 'Gold', 'price' => null, 'translations' => []], ['value' => 'gold-2', 'label' => 'Gold', 'price' => null, 'translations' => []], ['value' => '12-ct', 'label' => '12 ct', 'price' => '4.50', 'translations' => []]], $form->fields[1]['choices']);

        $renamed = $this->forms->update($form->id, new SaveForm('Engraving', fields: [new SaveFormField('Text to engrave', key: 'engraving_text')]));
        self::assertSame(['engraving_text'], array_column($renamed->fields, 'key'));
    }

    public function testAConditionLooksAtAnEarlierFieldAndItsValues(): void
    {
        $fields = [
            new SaveFormField('Engrave', 'choice', 'engrave', choices: [new SaveFieldChoice('Yes', 'yes')]),
            new SaveFormField('Text', key: 'text', condition: new SaveFieldCondition('engrave', ['yes'])),
        ];
        $form = $this->forms->create(new SaveForm('Engraving', fields: $fields));
        self::assertSame(['field' => 'f' . $form->id . '_engrave', 'values' => ['yes']], $this->shop->productFields(7, [])[1]->condition);

        foreach ([
            [new SaveFormField('Text', key: 'text', condition: new SaveFieldCondition('later')), new SaveFormField('Later', key: 'later')],
            [$fields[0], new SaveFormField('Text', key: 'text', condition: new SaveFieldCondition('engrave', ['maybe']))],
        ] as $broken) {
            try {
                $this->forms->create(new SaveForm('Broken', fields: $broken));
                self::fail('The form was saved.');
            } catch (FormRuleViolation $e) {
                self::assertSame('orillacart_invalid_form_field', $e->errorCode);
            }
        }
    }

    public function testCheckoutQuestionsHaveNoPriceAndTakeNoFiles(): void
    {
        foreach ([new SaveFormField('Gift wrap', 'checkbox', price: '3'), new SaveFormField('Logo', 'file')] as $field) {
            try {
                $checkout = $this->forms->create(new SaveForm('Checkout', 'checkout', fields: [$field]));
                self::fail('The form was saved.');
            } catch (FormRuleViolation) {
                self::assertSame([], $this->forms->forms());
            }
        }
        $this->expectException(FormRuleViolation::class);
        $this->forms->update($this->forms->create(new SaveForm('Product'))->id, new SaveForm('Product', 'checkout'));
    }

    public function testFormsAreAskedForTheirProducts(): void
    {
        $all = $this->forms->create(new SaveForm('All', position: 2, fields: [new SaveFormField('Name', key: 'name')]));
        $rings = $this->forms->create(new SaveForm('Rings', position: 1, scope: 'categories', categoryIds: [5], fields: [new SaveFormField('Size', key: 'size')]));
        $this->forms->create(new SaveForm('One', scope: 'products', productIds: [9], fields: [new SaveFormField('Logo', 'file', 'logo')]));
        $this->forms->create(new SaveForm('Off', active: false, fields: [new SaveFormField('Off', key: 'off')]));
        $checkout = $this->forms->create(new SaveForm('Checkout', 'checkout', scope: 'categories', categoryIds: [5], fields: [new SaveFormField('PO number', key: 'po', section: 'billing')]));

        $ids = self::ids(...);
        self::assertSame(["f{$rings->id}_size", "f{$all->id}_name"], $ids($this->shop->productFields(7, [3, 5])));
        self::assertSame(["f{$all->id}_name"], $ids($this->shop->productFields(7, [3])));
        self::assertSame(["f{$checkout->id}_po"], $ids($this->shop->checkoutFields([['product_id' => 7, 'category_ids' => [2]], ['product_id' => 8, 'category_ids' => [5]]])));
        self::assertSame([], $this->shop->checkoutFields([['product_id' => 7, 'category_ids' => [2]]]));

        $this->expectException(FormRuleViolation::class);
        $this->forms->create(new SaveForm('Nothing chosen', scope: 'categories'));
    }

    public function testDeletesAForm(): void
    {
        $form = $this->forms->create(new SaveForm('Engraving'));
        $this->forms->delete($form->id);

        $this->expectException(FormNotFound::class);
        $this->forms->form($form->id);
    }

    /**
     * @param list<AskedField> $fields
     *
     * @return list<string>
     */
    private static function ids(array $fields): array
    {
        return array_map(static fn(AskedField $field): string => $field->id, $fields);
    }
}
