<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

use OrillaCart\Forms\Application\Dto\AskedField;
use OrillaCart\Forms\Application\Dto\GivenAnswer;
use OrillaCart\Forms\Domain\CleanText;
use OrillaCart\Forms\Domain\FormUploadRepository;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\Money;

/**
 * Checks shoppers' answers against the fields asked, the shop's and add-ons' alike. Only the fields asked are read,
 * each as its kind says; nothing else of what was sent is kept. A field whose condition does not hold is not
 * asked, and its answer is dropped.
 */
final readonly class FieldAnswers
{
    /** What a ticked box is kept as. */
    public const string YES = 'Yes';
    /** The most the answers of one item or one order may come to, as sent. */
    public const int MAX_BYTES = 65536;

    private const string NUMBER = '/^-?\d{1,11}(?:\.\d{1,4})?$/';
    private const string TOKEN = '/^[A-Za-z0-9_-]{43}$/';
    private const string KEPT_FILE = '/^#([1-9]\d{0,18})$/';

    public function __construct(private FormUploadRepository $uploads) {}

    /**
     * @param list<AskedField>                              $fields  in the order asked
     * @param mixed                                         $sent    by field id, as the form sent them
     * @param string                                        $prefix  names a refused answer: "fields." or "personalisation."
     * @param bool                                          $kept    the values a cart kept from an earlier check: files as #<id>,
     *                                                               not a shopper's token
     * @param (\Closure(AskedField, string): ?string)|null  $check   more checks of an answer, such as an add-on's; a message refuses it
     *
     * @return list<GivenAnswer> the answered fields, in order
     *
     * @throws InvalidFields each refused answer next to its field
     */
    public function check(array $fields, mixed $sent, string $prefix, bool $kept = false, ?\Closure $check = null): array
    {
        $sent = \is_array($sent) ? $sent : [];
        if (\strlen((string) json_encode($sent, JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_BYTES) {
            throw new InvalidFields([['field' => rtrim($prefix, '.'), 'message' => 'That is too much to send.']]);
        }
        $answers = [];
        $values = [];
        $violations = [];
        foreach ($fields as $field) {
            if (!self::asked($field, $values)) {
                continue;
            }
            [$answer, $value, $uploadId, $problem] = $this->read($field, $sent[$field->id] ?? null, $kept);
            if ($problem === null && $answer === '' && $field->required) {
                $problem = match ($field->kind) {
                    'checkbox' => 'Tick this to go on.',
                    'file' => 'Choose a file.',
                    default => 'This field is required.',
                };
            }
            if ($problem === null && $answer !== '' && $check !== null) {
                $problem = $check($field, $answer);
            }
            if ($problem !== null) {
                $violations[] = ['field' => $prefix . $field->id, 'message' => $problem];
                continue;
            }
            if ($answer !== '') {
                $values[$field->id] = $value;
                $answers[] = new GivenAnswer($field->id, $field->label, $answer, $value, $field->priceOf($value), $uploadId);
            }
        }
        if ($violations !== []) {
            throw new InvalidFields($violations);
        }

        return $answers;
    }

    /**
     * What the answers add to each item.
     *
     * @param list<GivenAnswer> $answers
     */
    public static function price(array $answers): Money
    {
        return array_reduce($answers, static fn(Money $sum, GivenAnswer $answer): Money => $answer->price === null ? $sum : $sum->add(Money::of($answer->price)), Money::zero());
    }

    /**
     * The values to keep, by field id: what check() takes again with $kept.
     *
     * @param list<GivenAnswer> $answers
     *
     * @return array<string, string> by field id, sorted
     */
    public static function values(array $answers): array
    {
        $values = [];
        foreach ($answers as $answer) {
            $values[$answer->id] = $answer->value;
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<string, string> $values the answers so far, by field id
     */
    private static function asked(AskedField $field, array $values): bool
    {
        if ($field->condition === null) {
            return true;
        }
        $other = $values[$field->condition['field']] ?? null;

        return $other !== null && ($field->condition['values'] === [] || \in_array($other, $field->condition['values'], true));
    }

    /**
     * The answer as shown ('' for none), the value kept, the upload, and the problem with it.
     *
     * @return array{string, string, ?int, ?string}
     */
    private function read(AskedField $field, mixed $raw, bool $kept): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return ['', '', null, null];
        }
        if ($field->kind === 'checkbox') {
            $ticked = \in_array($raw, [true, 1, '1', 'yes', 'on', 'true'], true);

            return $ticked ? [self::YES, '1', null, null] : ['', '', null, null];
        }
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            return ['', '', null, 'This answer is not valid.'];
        }
        $text = CleanText::of((string) $raw, $field->kind === 'textarea');
        if ($text === null) {
            return ['', '', null, 'This answer is not valid text.'];
        }
        if ($text === '') {
            return ['', '', null, null];
        }

        return match ($field->kind) {
            'number' => self::number($field, $text),
            'date' => self::date($text),
            'choice' => self::choice($field, $text),
            'file' => $this->file($field, $text, $kept),
            default => mb_strlen($text) > $field->longest ? ['', '', null, \sprintf('Up to %d characters.', $field->longest)] : [$text, $text, null, null],
        };
    }

    /** @return array{string, string, ?int, ?string} */
    private static function number(AskedField $field, string $text): array
    {
        if (preg_match(self::NUMBER, $text) !== 1 || ($field->whole && str_contains($text, '.'))) {
            return ['', '', null, $field->whole ? 'Enter a whole number.' : 'Enter a number.'];
        }
        $number = Money::of($text);
        if (($field->min !== null && $number->compare(Money::of($field->min)) < 0) || ($field->max !== null && $number->compare(Money::of($field->max)) > 0)) {
            $range = match (true) {
                $field->min !== null && $field->max !== null => \sprintf('from %s to %s', $field->min, $field->max),
                $field->min !== null => \sprintf('of at least %s', $field->min),
                default => \sprintf('of at most %s', $field->max),
            };

            return ['', '', null, \sprintf('Enter a number %s.', $range)];
        }
        $value = (string) $number;

        return [$value, $value, null, null];
    }

    /** @return array{string, string, ?int, ?string} */
    private static function date(string $text): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[1] < 1900 || (int) $parts[1] > 2200) {
            return ['', '', null, 'Enter a date.'];
        }

        return [$text, $text, null, null];
    }

    /** @return array{string, string, ?int, ?string} */
    private static function choice(AskedField $field, string $text): array
    {
        foreach ($field->choices as $choice) {
            if ($choice['value'] === $text) {
                return [$choice['label'], $text, null, null];
            }
        }

        return ['', '', null, 'This answer is not one of the choices.'];
    }

    /**
     * A shopper names their upload by its token; a cart, which checked it before, by its id.
     *
     * @return array{string, string, ?int, ?string}
     */
    private function file(AskedField $field, string $text, bool $kept): array
    {
        $upload = null;
        if ($kept && preg_match(self::KEPT_FILE, $text, $id) === 1) {
            $upload = $this->uploads->find((int) $id[1]);
        } elseif (!$kept && preg_match(self::TOKEN, $text) === 1) {
            $upload = $this->uploads->withToken($text);
        }
        if ($upload === null) {
            return ['', '', null, 'This file is no longer here; send it again.'];
        }
        if (!\in_array($upload->mimeType(), $field->types, true) || $upload->size() > $field->largest * 1024 * 1024) {
            return ['', '', null, 'This file is not one this field takes; send another.'];
        }

        return [$upload->filename(), '#' . $upload->id(), $upload->id(), null];
    }
}
