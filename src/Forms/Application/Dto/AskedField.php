<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

/**
 * A question as the shopper is asked it, the shop's or an add-on's: what the page shows and what the answers
 * are checked against.
 */
final readonly class AskedField
{
    /**
     * @param string                                                   $id        names the answer: f12_engraving, or an add-on's own id
     * @param string                                                   $kind      text, textarea, number, date, checkbox, choice or file
     * @param list<array{value: string, label: string, price: ?string}> $choices  for a choice, in the order to show
     * @param string                                                   $display   a choice's: list or buttons
     * @param string|null                                              $price     what an answer adds to each item (a choice's own instead)
     * @param string                                                   $section   at checkout: billing, shipping or order
     * @param array{field: string, values: list<string>}|null          $condition asked only when that field (by id) is answered, or has one of the values
     * @param list<string>                                             $types     a file field's types
     * @param int                                                      $largest   a file's largest size, in megabytes
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public bool $required = false,
        public string $help = '',
        public string $placeholder = '',
        public int $longest = 200,
        public array $choices = [],
        public string $display = 'list',
        public ?string $price = null,
        public ?string $min = null,
        public ?string $max = null,
        public bool $whole = false,
        public string $section = 'order',
        public ?array $condition = null,
        public array $types = [],
        public int $largest = 10,
    ) {}

    /** What an item adds with this answer (a choice by its value). */
    public function priceOf(string $value): ?string
    {
        if ($this->kind !== 'choice') {
            return $this->price;
        }
        foreach ($this->choices as $choice) {
            if ($choice['value'] === $value) {
                return $choice['price'];
            }
        }

        return null;
    }
}
