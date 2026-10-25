<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;

/**
 * The texts of the legal pages the setup drafts, as blocks the owner edits in the page editor, in the shop's
 * language. They are a start, not legal advice: each begins by saying so, for the owner to check and remove.
 * The right of withdrawal follows the EU's model (Directive 2011/83/EU, Annex I), with its model form.
 */
final readonly class LegalTexts
{
    public function __construct(private Translations $translations) {}

    /**
     * @param array{name: string, address: string, email: string, phone: string} $seller
     */
    public function terms(array $seller, string $country, int $withdrawalDays, string $withdrawalTitle): string
    {
        $t = fn(string $template, string|int ...$values): string => $this->translations->text(Text::of($template, ...$values));

        return $this->draftNote() . implode('', [
            self::heading($t('1. Scope')),
            self::paragraph($t('These terms apply to every order placed in this online shop with %1$s, %2$s.', $seller['name'], $seller['address'])),
            self::heading($t('2. The contract')),
            self::paragraph($t('The products in the shop are not a binding offer. By pressing the order button at checkout you make a binding offer to buy the products in your cart. We confirm your order by email; the contract is made when we accept it, at the latest when we ship the goods.')),
            self::paragraph($t('The contract is made in the language of the shop. We keep its text, and you receive it with the order confirmation.')),
            self::heading($t('3. Prices and shipping costs')),
            self::paragraph($t('The prices are final prices and include the statutory tax where it applies. Shipping costs are added; they show at checkout before you order, and on the page "Shipping and payment".')),
            self::heading($t('4. Payment')),
            self::paragraph($t('You pay in one of the ways checkout offers. Where you pay in advance, we ship once the payment has reached us.')),
            self::heading($t('5. Delivery')),
            self::paragraph($t('We deliver to the countries checkout offers. The delivery time shows on each product, or at checkout.')),
            self::heading($t('6. Retention of title')),
            self::paragraph($t('The goods remain our property until they are paid in full.')),
            self::heading($t('7. Right of withdrawal')),
            self::paragraph($withdrawalDays > 0
                ? $t('As a consumer you may withdraw from the contract within %1$d days without giving any reason, as the page "%2$s" sets out.', $withdrawalDays, $withdrawalTitle)
                : $t('Whether you may withdraw from the contract depends on the law of your country.')),
            self::heading($t('8. Warranty')),
            self::paragraph($t('The statutory rights for defective goods apply.')),
            self::heading($t('9. Applicable law')),
            self::paragraph($t('The law of %s applies. As a consumer you keep the protection the mandatory law of the country where you live gives you.', $country)),
            self::heading($t('10. Contact')),
            self::paragraph($t('%1$s, %2$s, email %3$s.', $seller['name'], $seller['address'], $seller['email'])),
        ]);
    }

    /**
     * @param array{name: string, address: string, email: string, phone: string} $seller
     */
    public function withdrawal(array $seller, int $days): string
    {
        $t = fn(string $template, string|int ...$values): string => $this->translations->text(Text::of($template, ...$values));
        $contact = implode(', ', array_filter([$seller['name'], $seller['address'], $seller['phone'], $seller['email']], static fn(string $part): bool => $part !== ''));

        return $this->draftNote() . implode('', [
            self::heading($t('Right of withdrawal')),
            self::paragraph($t('You have the right to withdraw from this contract within %d days without giving any reason.', $days)),
            self::paragraph($t('The withdrawal period will expire after %d days from the day on which you acquire, or a third party other than the carrier and indicated by you acquires, physical possession of the goods.', $days)),
            self::paragraph($t('To exercise the right of withdrawal, you must inform us (%s) of your decision to withdraw from this contract by an unequivocal statement (e.g. a letter sent by post or email). You may use the model withdrawal form below, but it is not obligatory.', $contact)),
            self::paragraph($t('To meet the withdrawal deadline, it is sufficient for you to send your communication concerning your exercise of the right of withdrawal before the withdrawal period has expired.')),
            self::heading($t('Effects of withdrawal')),
            self::paragraph($t('If you withdraw from this contract, we shall reimburse to you all payments received from you, including the costs of delivery (with the exception of the supplementary costs resulting from your choice of a type of delivery other than the least expensive type of standard delivery offered by us), without undue delay and in any event not later than 14 days from the day on which we are informed about your decision to withdraw from this contract. We will carry out such reimbursement using the same means of payment as you used for the initial transaction, unless you have expressly agreed otherwise; in any event, you will not incur any fees as a result of such reimbursement.')),
            self::paragraph($t('We may withhold reimbursement until we have received the goods back or you have supplied evidence of having sent back the goods, whichever is the earliest.')),
            self::paragraph($t('You shall send back the goods or hand them over to us without undue delay and in any event not later than 14 days from the day on which you communicate your withdrawal from this contract to us. The deadline is met if you send back the goods before the period of 14 days has expired.')),
            self::paragraph($t('You will have to bear the direct cost of returning the goods.')),
            self::paragraph($t('You are only liable for any diminished value of the goods resulting from the handling other than what is necessary to establish the nature, characteristics and functioning of the goods.')),
            self::heading($t('Model withdrawal form')),
            self::paragraph($t('(Complete and return this form only if you wish to withdraw from the contract.)')),
            self::items([
                $t('To %s:', $contact),
                $t('I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract of sale of the following goods (*)/for the provision of the following service (*),'),
                $t('Ordered on (*)/received on (*),'),
                $t('Name of consumer(s),'),
                $t('Address of consumer(s),'),
                $t('Signature of consumer(s) (only if this form is notified on paper),'),
                $t('Date'),
            ]),
            self::paragraph($t('(*) Delete as appropriate.')),
        ]);
    }

    /** A page that shows a block of the shop's, kept up to date by it. */
    public static function block(string $name): string
    {
        return \sprintf('<!-- wp:orillacart/%s /-->', $name);
    }

    private function draftNote(): string
    {
        return self::paragraph('<strong>' . self::escape($this->translations->text(Text::of('Draft by OrillaCart\'s setup: a starting point, not legal advice. Check it against the law that applies to your shop, or have a lawyer check it, then delete this line and publish the page.'))) . '</strong>', true);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function heading(string $text): string
    {
        return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . self::escape($text) . "</h2>\n<!-- /wp:heading -->\n\n";
    }

    private static function paragraph(string $text, bool $html = false): string
    {
        return "<!-- wp:paragraph -->\n<p>" . ($html ? $text : self::escape($text)) . "</p>\n<!-- /wp:paragraph -->\n\n";
    }

    /** @param list<string> $items */
    private static function items(array $items): string
    {
        $list = implode('', array_map(static fn(string $item): string => "<!-- wp:list-item -->\n<li>" . self::escape($item) . "</li>\n<!-- /wp:list-item -->\n", $items));

        return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $list . "</ul>\n<!-- /wp:list -->\n\n";
    }
}
