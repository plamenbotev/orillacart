<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\AskedByCountry;
use OrillaCart\Setup\Application\Dto\LegalAnswers;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\LegalTexts;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Domain\LegalProfile;
use OrillaCart\Setup\Domain\LegalProfiles;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Storefront\Application\LegalSettingsService;
use OrillaCart\Storefront\Domain\OrderButton;

/**
 * Legal: the pages the law of the shop's country asks for (terms of sale and the right of withdrawal as drafts to
 * check; shipping and payment and the legal notice from the shop's own settings, published), what checkout asks
 * before an order, and the rules for consumers (days to return, the lowest price before a discount).
 */
final readonly class LegalStep implements SetupStep, AskedByCountry
{
    public const string KEY = 'legal';
    private const string PAGE = 'legal.page.';
    private const string CHECKOUT = 'legal.checkout';
    private const string CONSUMERS = 'legal.consumers';
    /** Days the EU gives to withdraw, which the shop's return window is at least. */
    private const int RETURN_DAYS = 14;

    public function __construct(
        private LegalSettingsService $legal,
        private SettingsService $settings,
        private GeographyService $geography,
        private LegalTexts $texts,
        private Translations $translations,
        private ShopFacts $facts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 60;
    }

    public function answers(): string
    {
        return LegalAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $legal = $answers->of(self::KEY, LegalAnswers::class);
        $country = $this->facts->country($answers);
        if ($legal === null || $country === null) {
            return [];
        }
        $profile = LegalProfiles::of($country);
        $current = $this->legal->current();
        $changes = [];
        if ($legal->pages) {
            foreach ($this->pages($profile) as $part => [$title, $draft]) {
                $chosen = match ($part) {
                    'terms' => $current->terms,
                    'withdrawal' => $current->withdrawal,
                    'delivery' => $current->delivery,
                    default => $current->legalNotice,
                };
                if ($chosen === null) {
                    $changes[] = new PlannedChange(self::PAGE . $part, self::KEY, $draft
                        ? Text::of('A draft page "%s" for you to check and publish.', $title)
                        : Text::of('A page "%s", kept up to date from the shop\'s settings.', $title), data: ['title' => $title]);
                }
            }
        }
        $accept = $legal->acceptTerms ?? $profile->consumerCheckout;
        $button = $legal->orderButton ?? ($profile->consumerCheckout ? OrderButton::BuyNow->value : OrderButton::PlaceOrder->value);
        if ($current->acceptTerms !== $accept || $current->orderButton !== $button) {
            $changes[] = new PlannedChange(self::CHECKOUT, self::KEY, $accept
                ? Text::of('Checkout asks customers to accept the terms of sale, and its button says "%s".', self::button($button))
                : Text::of('Checkout\'s button says "%s".', self::button($button)), data: ['accept' => $accept, 'button' => $button]);
        }
        if ($profile->withdrawalDays !== null) {
            $shop = $this->settings->current();
            $days = max(self::RETURN_DAYS, $shop->returnDays ?? 0);
            if ($shop->returnDays !== $days || !$shop->showLowestPrice) {
                $changes[] = new PlannedChange(self::CONSUMERS, self::KEY, Text::of('The rules for consumers:'), data: ['days' => $days], details: [
                    Text::of('Customers can ask to return goods for %d days after delivery.', $days),
                    Text::of('A discounted price shows the lowest price of the last 30 days beside it.'),
                ]);
            }
        }

        return $changes;
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $country = $this->facts->country($answers);
        if ($country === null) {
            return;
        }
        $profile = LegalProfiles::of($country);
        if (str_starts_with($change->key, self::PAGE)) {
            $part = substr($change->key, \strlen(self::PAGE));
            $title = \is_string($change->data['title'] ?? null) ? $change->data['title'] : '';
            [$name, $address] = $this->facts->seller($answers);
            $seller = ['name' => $name, 'address' => $address, 'email' => $this->facts->email($answers), 'phone' => $this->facts->phone($answers)];
            $withdrawalTitle = $this->translations->text(Text::of('Right of withdrawal'));
            [$content, $publish] = match ($part) {
                'terms' => [$this->texts->terms($seller, $this->countryName($country), $profile->withdrawalDays ?? 0, $withdrawalTitle), false],
                'withdrawal' => [$this->texts->withdrawal($seller, $profile->withdrawalDays ?? 14), false],
                'delivery' => [LegalTexts::block('shipping-payment'), true],
                default => [LegalTexts::block('legal-notice'), true],
            };
            $this->legal->makePage($part, $title, $content, $publish);
        } elseif ($change->key === self::CHECKOUT) {
            $button = \is_string($change->data['button'] ?? null) ? $change->data['button'] : OrderButton::PlaceOrder->value;
            $this->legal->checkout(($change->data['accept'] ?? false) === true, OrderButton::from($button));
        } elseif ($change->key === self::CONSUMERS) {
            $this->settings->consumerRules(\is_int($change->data['days'] ?? null) ? $change->data['days'] : self::RETURN_DAYS, true);
        }
    }

    /**
     * The pages the country's law asks for: the terms everywhere; the right of withdrawal where consumers have
     * one; shipping and payment; the legal notice where the seller must be named.
     *
     * @return array<string, array{string, bool}> [title, a draft to check] by part
     */
    private function pages(LegalProfile $profile): array
    {
        $title = fn(string $words): string => $this->translations->text(Text::of($words));
        $pages = ['terms' => [$title('Terms of sale'), true]];
        if ($profile->withdrawalDays !== null) {
            $pages['withdrawal'] = [$title('Right of withdrawal'), true];
        }
        $pages['delivery'] = [$title('Shipping and payment'), false];
        if ($profile->legalNotice) {
            $pages['legal_notice'] = [$profile->legalNoticeTitle === 'Impressum' ? 'Impressum' : $title('Legal notice'), false];
        }

        return $pages;
    }

    private static function button(string $button): Text
    {
        return match (OrderButton::from($button)) {
            OrderButton::BuyNow => Text::of('Buy now'),
            OrderButton::OrderAndPay => Text::of('Order with obligation to pay'),
            OrderButton::PlaceOrder => Text::of('Place order'),
        };
    }

    private function countryName(string $code): string
    {
        foreach ($this->geography->countries() as $country) {
            if ($country->code === $code) {
                return $country->name;
            }
        }

        return $code;
    }
}
