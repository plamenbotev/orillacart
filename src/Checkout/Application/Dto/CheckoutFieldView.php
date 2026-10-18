<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * A question an add-on asks at checkout, as the form shows it.
 */
final readonly class CheckoutFieldView
{
    /**
     * @param string                                    $kind    text, textarea, checkbox or choice
     * @param string                                    $section billing, shipping or order: where the form asks it
     * @param list<array{value: string, label: string}> $choices for a choice, in the order to show
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public string $section,
        public bool $required,
        public array $choices,
        public string $help,
        public int $longest,
    ) {}
}
