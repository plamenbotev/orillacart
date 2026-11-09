<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Storefront\ProductBlock;
use OrillaCart\Extension\Storefront\ShownProduct;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\HtmlCleaner;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * `product_block "id" { title, description; fn render(product, settings) }`: a block for product pages; what the
 * script renders is cleaned as a post's HTML is, so a script cannot put a script tag on the storefront.
 */
final class ScriptedProductBlock extends ScriptedProvider implements ProductBlock
{
    public function __construct(LoadedPackage $loaded, array $declaration, ScriptRunner $runner, private readonly HtmlCleaner $cleaner)
    {
        parent::__construct($loaded, $declaration, $runner);
    }

    public function title(): string
    {
        $title = $this->declaration['props']['title'] ?? null;

        return \is_string($title) ? $this->loaded->word($title) : parent::title();
    }

    public function description(): string
    {
        $description = $this->declaration['props']['description'] ?? null;

        return \is_string($description) ? $this->loaded->word($description) : '';
    }

    public function render(ShownProduct $product, array $settings): string
    {
        try {
            $html = $this->call('render', [Values::record($product), $settings]);
        } catch (HandlerFailed) {
            return '';
        }

        return $this->cleaner->clean(Values::text($html));
    }
}
