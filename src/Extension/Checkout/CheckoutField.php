<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * A question checkout asks besides the shop's own: a VAT number, a company, a delivery date, a gift message. The
 * order keeps each answer with the label it had, so old orders show it after the field is gone. The receipt,
 * the emails and the order screen show the answers; the order screen edits them with the contact details.
 */
final readonly class CheckoutField
{
    public const string TEXT = 'text';
    public const string TEXTAREA = 'textarea';
    /** Yes or no; required means it has to be ticked, as for accepting terms. */
    public const string CHECKBOX = 'checkbox';
    /** One of $choices. */
    public const string CHOICE = 'choice';

    /** Asked with the billing address. */
    public const string BILLING = 'billing';
    /** Asked with the shipping address, only for orders that ship. */
    public const string SHIPPING = 'shipping';
    /** Asked with the order's note. */
    public const string ORDER = 'order';

    /**
     * @param string                $id      lowercase letters, digits and underscores, up to 40; unique among fields
     * @param array<string, string> $choices for CHOICE: value => label, in the order to show
     * @param int                   $longest the most characters an answer may have
     * @param FieldCheck|null       $check   checks an answer beyond what the field says, such as a VAT number with the tax office
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind = self::TEXT,
        public string $section = self::BILLING,
        public bool $required = false,
        public array $choices = [],
        public string $help = '',
        public int $longest = 200,
        public ?FieldCheck $check = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a field id: lowercase letters, digits and underscores, up to 40.', $id));
        }
        if (trim($label) === '') {
            throw new \InvalidArgumentException(\sprintf('The field "%s" has no label.', $id));
        }
        if (!\in_array($kind, [self::TEXT, self::TEXTAREA, self::CHECKBOX, self::CHOICE], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a kind of field.', $kind));
        }
        if (!\in_array($section, [self::BILLING, self::SHIPPING, self::ORDER], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a part of checkout.', $section));
        }
        if ($kind === self::CHOICE && $choices === []) {
            throw new \InvalidArgumentException(\sprintf('The field "%s" offers no choices.', $id));
        }
        if ($longest < 1 || $longest > 2000) {
            throw new \InvalidArgumentException(\sprintf('The field "%s" takes from 1 to 2000 characters.', $id));
        }
    }
}
