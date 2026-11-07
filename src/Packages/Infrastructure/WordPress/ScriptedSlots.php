<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Packages\Application\ScriptRunner;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\SlotContent;
use Psr\Log\LoggerInterface;

/**
 * `slot "product_after_details" { template: "note.html.twig" } fn(context)`: the extension's template printed in
 * one of the storefront's slots, with what the slot gives and what the handler adds.
 */
final readonly class ScriptedSlots implements SlotContent
{
    public function __construct(
        private PackageService $packages,
        private ScriptRunner $runner,
        private TemplateRenderer $templates,
        private LoggerInterface $logger,
    ) {}

    public function render(string $slot, array $arguments): string
    {
        $html = '';
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($loaded->manifest->ofKind('slot') as $declaration) {
                $template = $declaration['props']['template'] ?? null;
                if ($declaration['name'] !== $slot || !\is_string($template)) {
                    continue;
                }
                $html .= $this->one($loaded, $declaration, $template, $arguments);
            }
        }

        return $html;
    }

    /**
     * @param array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string} $declaration
     * @param list<mixed>                                                                                                                                                                  $arguments
     */
    private function one(LoadedPackage $loaded, array $declaration, string $template, array $arguments): string
    {
        $context = ['slot' => $declaration['name'], 'arguments' => Ops::plain(array_map(static fn(mixed $argument): mixed => \is_object($argument) ? Values::record($argument) : $argument, $arguments))];
        if ($declaration['handler'] !== null) {
            try {
                $added = $this->runner->run($loaded, $declaration['handler'], [$context]);
            } catch (HandlerFailed) {
                return '';
            }
            $context = self::map($added) + $context;
        }
        try {
            return $this->templates->render(PackageTemplates::PREFIX . $loaded->package->slug() . '/' . ltrim($template, '/'), $context);
        } catch (\Throwable $e) {
            $this->logger->warning('The extension "{extension}" could not render its slot {slot}: {message}', ['extension' => $loaded->package->slug(), 'slot' => $declaration['name'], 'message' => $e->getMessage()]);

            return '';
        }
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        $map = [];
        $plain = \is_array($value) ? Ops::plain($value) : [];
        foreach (\is_array($plain) ? $plain : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
