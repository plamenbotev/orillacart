<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

/**
 * An answer as kept: the label the field had, the answer as shown (a choice by its label, a ticked box as "Yes", a
 * file by its name), what it adds to each item, and the value the cart keeps to check it again.
 */
final readonly class GivenAnswer
{
    /**
     * @param string      $value    a choice's value, a number as written plainly, a file as #<upload id>
     * @param string|null $price    added to each item, in the shop's currency
     * @param int|null    $uploadId the file sent, for a file field
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $answer,
        public string $value,
        public ?string $price = null,
        public ?int $uploadId = null,
    ) {}
}
