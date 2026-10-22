<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Forms;

use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Application\FieldAnswers;
use OrillaCart\Forms\Domain\FormUpload;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Tests\Fixtures\Forms\InMemoryFormUploadRepository;
use PHPUnit\Framework\TestCase;

final class FieldAnswersTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    private InMemoryFormUploadRepository $uploads;
    private FieldAnswers $answers;

    protected function setUp(): void
    {
        $this->uploads = new InMemoryFormUploadRepository();
        $this->answers = new FieldAnswers($this->uploads);
    }

    public function testOnlyTheFieldsAskedAreReadAndKeptAsShown(): void
    {
        $answers = $this->answers->check([
            new AskedField('f1_name', 'Name', 'text', price: '5'),
            new AskedField('f1_box', 'Box', 'choice', choices: [['value' => 'gold', 'label' => 'Gold box', 'price' => '3.5'], ['value' => 'plain', 'label' => 'Plain', 'price' => null]]),
            new AskedField('f1_wrap', 'Wrap it', 'checkbox'),
            new AskedField('f1_note', 'Note', 'textarea'),
        ], ['f1_name' => '  Ada ', 'f1_box' => 'gold', 'f1_wrap' => 'on', 'f1_note' => '', 'price' => '0', 'f1_other' => 'x'], 'personalisation.');

        self::assertEquals([
            new GivenAnswer('f1_name', 'Name', 'Ada', 'Ada', '5'),
            new GivenAnswer('f1_box', 'Box', 'Gold box', 'gold', '3.5'),
            new GivenAnswer('f1_wrap', 'Wrap it', FieldAnswers::YES, '1'),
        ], $answers);
        self::assertSame('8.5', (string) FieldAnswers::price($answers));
        self::assertSame(['f1_box' => 'gold', 'f1_name' => 'Ada', 'f1_wrap' => '1'], FieldAnswers::values($answers));
    }

    public function testControlAndDirectionCharactersAreRemoved(): void
    {
        $answers = $this->answers->check([
            new AskedField('f1_name', 'Name', 'text'),
            new AskedField('f1_note', 'Note', 'textarea'),
        ], ['f1_name' => "Ad\u{202E}a\x00\x1B[31m\u{2066}", 'f1_note' => "one\r\ntwo\x07\u{FEFF}"], 'personalisation.');

        self::assertSame(['Ada[31m', "one\ntwo"], array_map(static fn(GivenAnswer $answer): string => $answer->answer, $answers));
    }

    /**
     * @return iterable<string, array{AskedField, mixed, string}>
     */
    public static function refused(): iterable
    {
        yield 'required' => [new AskedField('f1_a', 'A', 'text', required: true), '  ', 'This field is required.'];
        yield 'required box' => [new AskedField('f1_a', 'A', 'checkbox', required: true), 'no', 'Tick this to go on.'];
        yield 'too long' => [new AskedField('f1_a', 'A', 'text', longest: 3), 'abcd', 'Up to 3 characters.'];
        yield 'counted in characters' => [new AskedField('f1_a', 'A', 'text', longest: 3), 'жжжж', 'Up to 3 characters.'];
        yield 'an array' => [new AskedField('f1_a', 'A', 'text'), ['x'], 'This answer is not valid.'];
        yield 'not UTF-8' => [new AskedField('f1_a', 'A', 'text'), "\xC3\x28", 'This answer is not valid text.'];
        yield 'exponent' => [new AskedField('f1_a', 'A', 'number'), '1e3', 'Enter a number.'];
        yield 'hex' => [new AskedField('f1_a', 'A', 'number'), '0x10', 'Enter a number.'];
        yield 'five decimals' => [new AskedField('f1_a', 'A', 'number'), '1.12345', 'Enter a number.'];
        yield 'not whole' => [new AskedField('f1_a', 'A', 'number', whole: true), '2.5', 'Enter a whole number.'];
        yield 'below' => [new AskedField('f1_a', 'A', 'number', min: '1', max: '10'), '0', 'Enter a number from 1 to 10.'];
        yield 'above' => [new AskedField('f1_a', 'A', 'number', max: '10'), '10.01', 'Enter a number of at most 10.'];
        yield 'no such day' => [new AskedField('f1_a', 'A', 'date'), '2026-02-30', 'Enter a date.'];
        yield 'too early' => [new AskedField('f1_a', 'A', 'date'), '1800-01-01', 'Enter a date.'];
        yield 'with a time' => [new AskedField('f1_a', 'A', 'date'), '2026-02-03T10:00', 'Enter a date.'];
        yield 'not offered' => [new AskedField('f1_a', 'A', 'choice', choices: [['value' => 'a', 'label' => 'A', 'price' => null]]), 'b', 'This answer is not one of the choices.'];
        yield 'choice by label' => [new AskedField('f1_a', 'A', 'choice', choices: [['value' => 'a', 'label' => 'Big', 'price' => null]]), 'Big', 'This answer is not one of the choices.'];
        yield 'unknown token' => [new AskedField('f1_a', 'A', 'file', types: ['image/png']), self::TOKEN, 'This file is no longer here; send it again.'];
        yield 'an id from a shopper' => [new AskedField('f1_a', 'A', 'file', types: ['image/png']), '#1', 'This file is no longer here; send it again.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefusesAnswersThatDoNotHold(AskedField $field, mixed $sent, string $message): void
    {
        $this->uploads->save(new FormUpload('x', 'key', 'a.png', 'image/png', 10, new \DateTimeImmutable()));
        try {
            $this->answers->check([$field], [$field->id => $sent], 'personalisation.');
            self::fail('The answer was taken.');
        } catch (InvalidFields $e) {
            self::assertSame([['field' => 'personalisation.' . $field->id, 'message' => $message]], $e->violations);
        }
    }

    public function testNumbersAndDatesAreKeptPlainly(): void
    {
        $answers = $this->answers->check([
            new AskedField('f1_n', 'N', 'number', min: '-5', max: '100'),
            new AskedField('f1_d', 'D', 'date'),
        ], ['f1_n' => '007.50', 'f1_d' => '2024-02-29'], 'personalisation.');

        self::assertSame(['7.5', '2024-02-29'], array_map(static fn(GivenAnswer $answer): string => $answer->answer, $answers));
    }

    public function testAFieldWhoseConditionDoesNotHoldIsNotAsked(): void
    {
        $fields = [
            new AskedField('f1_engrave', 'Engrave', 'choice', choices: [['value' => 'yes', 'label' => 'Yes', 'price' => '5'], ['value' => 'no', 'label' => 'No', 'price' => null]]),
            new AskedField('f1_text', 'Text', 'text', required: true, condition: ['field' => 'f1_engrave', 'values' => ['yes']]),
        ];

        $without = $this->answers->check($fields, ['f1_engrave' => 'no', 'f1_text' => 'Hidden'], 'personalisation.');
        self::assertSame(['f1_engrave'], array_map(static fn(GivenAnswer $answer): string => $answer->id, $without));

        $this->expectException(InvalidFields::class);
        $this->answers->check($fields, ['f1_engrave' => 'yes'], 'personalisation.');
    }

    public function testShoppersNameFilesByTokenAndCartsByItsHash(): void
    {
        $upload = new FormUpload(self::TOKEN, 'key', 'logo.png', 'image/png', 2048, new \DateTimeImmutable());
        $this->uploads->save($upload);
        $field = new AskedField('f1_logo', 'Logo', 'file', price: '2', types: ['image/png', 'application/pdf'], largest: 1);

        $sent = $this->answers->check([$field], ['f1_logo' => self::TOKEN], 'personalisation.');
        self::assertEquals([new GivenAnswer('f1_logo', 'Logo', 'logo.png', '#' . $upload->tokenHash(), '2', $upload->id())], $sent);
        self::assertEquals($sent, $this->answers->check([$field], FieldAnswers::values($sent), 'personalisation.', kept: true));

        try {
            $this->answers->check([$field], ['f1_logo' => self::TOKEN], 'personalisation.', kept: true);
            self::fail('a token where the cart keeps a hash');
        } catch (InvalidFields) {
        }
        $upload->takenBy(9);
        $this->expectException(InvalidFields::class);
        $this->answers->check([$field], FieldAnswers::values($sent), 'personalisation.', kept: true);
    }

    public function testAFileTheFieldDoesNotTakeIsRefused(): void
    {
        $this->uploads->save(new FormUpload(self::TOKEN, 'key', 'big.pdf', 'application/pdf', 2 * 1024 * 1024, new \DateTimeImmutable()));

        $this->expectException(InvalidFields::class);
        $this->answers->check([new AskedField('f1_logo', 'Logo', 'file', types: ['application/pdf'], largest: 1)], ['f1_logo' => self::TOKEN], 'personalisation.');
    }

    public function testTooMuchSentIsRefusedWhole(): void
    {
        $this->expectException(InvalidFields::class);
        $this->answers->check([new AskedField('f1_a', 'A', 'text')], ['f1_a' => 'x', 'junk' => str_repeat('x', FieldAnswers::MAX_BYTES)], 'personalisation.');
    }

    public function testAnotherCheckRefusesWithItsMessage(): void
    {
        $this->expectExceptionObject(new InvalidFields([['field' => 'fields.po', 'message' => 'Unknown order number.']]));
        $this->answers->check([new AskedField('po', 'PO', 'text')], ['po' => '42'], 'fields.', check: static fn(AskedField $field, string $answer): string => 'Unknown order number.');
    }
}
