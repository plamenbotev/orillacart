<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\ShopperCurrency;

/**
 * The currencies the shopper may choose, for the header or a sidebar: choosing one reloads the page in it. It
 * works without the storefront script, as a form. Nothing shows while the shop offers only its own currency.
 */
final readonly class CurrencySwitcherBlock implements BlockDefinition
{
    public function __construct(
        private Currencies $currencies,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/currency-switcher';
    }

    public function render(array $attributes, BlockContext $context): string
    {
        $codes = $this->currencies->codes();
        if (\count($codes) < 2) {
            return '';
        }

        return $this->templates->render('storefront/_currency_switcher.html.twig', [
            'codes' => $codes,
            'current' => $this->currencies->current(),
            'parameter' => ShopperCurrency::PARAMETER,
        ]);
    }
}
