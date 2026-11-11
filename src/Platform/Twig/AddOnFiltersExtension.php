<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;

/**
 * Add-ons' filters for every storefront template (extension API 24), the sandboxed ones included. Read when Twig
 * sets itself up, on the first render, so every add-on has hooked in by then; one named as a filter the shop has is
 * left out and logged, since Twig would let the later one replace it.
 */
final class AddOnFiltersExtension extends AbstractExtension
{
    public function __construct(
        private readonly InstalledExtensions $installed,
        private readonly Environment $twig,
        private readonly ?SecurityPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {}

    public function getFilters(): array
    {
        $taken = [];
        foreach ($this->twig->getExtensions() as $extension) {
            if ($extension !== $this) {
                foreach ($extension->getFilters() as $filter) {
                    $taken[$filter->getName()] = true;
                }
            }
        }
        $filters = [];
        foreach ($this->installed->templateFilters() as $filter) {
            $name = $filter->name();
            if (isset($taken[$name])) {
                $this->logger->error('An add-on\'s template filter is named "{name}", as one of the shop\'s; it is left out.', ['name' => $name]);
                continue;
            }
            $filters[] = new TwigFilter($name, static fn(mixed $value, mixed ...$arguments): mixed => $filter->apply($value, array_values($arguments)), $filter->isHtml() ? ['is_safe' => ['html']] : []);
        }
        // The sandboxed templates may use them too.
        $this->policy?->setAllowedFilters([...EmailTwig::FILTERS, ...array_map(static fn(TwigFilter $filter): string => $filter->getName(), $filters)]);

        return $filters;
    }
}
