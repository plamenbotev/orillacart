<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Scripting\Application\Dto\Finding;
use OrillaCart\Scripting\Application\Scripts;

/**
 * The words an extension shows people, to translate (design 57): those its declarations hold where a person reads
 * them (a setting's label, a payment method's name, a column's label), those its scripts' `__` calls mark, and
 * those its templates' do. Each with the places it stands; the same words in the same context are one. They are
 * the words of the files it runs (Package::running()): for an enabled extension the ones it was enabled with, so an
 * edit's words wait, as the edit does, until a manager enables it again.
 */
final readonly class ExtensionWords
{
    /** The declarations whose `name` the shop shows (the provider's title), with their `label`, where they have one. */
    public const array NAMED = ['payment_gateway', 'shipping_method', 'cart_rule', 'order_fee', 'price_adjuster', 'order_adjuster', 'checkout_field', 'order_action', 'order_column', 'product_column', 'order_filter', 'product_filter', 'home', 'product_block', 'site_block', 'mailing_list'];

    public function __construct(private Scripts $scripts, private ?TemplateWords $templates = null) {}

    /** @return list<ExtensionWord> in the order they first stand */
    public function of(Package $package): array
    {
        $words = [];
        $add = static function (?string $text, string $where, ?string $plural = null, string $context = '') use (&$words): void {
            if ($text === null || trim($text) === '') {
                return;
            }
            $word = new ExtensionWord($text, $plural, $context, [$where]);
            $words[$word->key()] = isset($words[$word->key()]) ? $words[$word->key()]->alsoAt($where) : $word;
        };

        $running = $package->running();
        $source = $package->runningSource();
        $compiled = $this->scripts->compile($source, $package->slug());
        if ($compiled->ok()) {
            $script = $this->scripts->load($source, $package->slug());
            self::declared(PackageManifest::ofClass($script::class)->all(), $add);
        }
        $scripts = array_filter($running, static fn(string $path): bool => str_ends_with($path, '.ocs'), \ARRAY_FILTER_USE_KEY);
        foreach ($compiled->words as $word) {
            $placed = PackageService::locate($scripts, [new Finding($word['line'], 1, '')])[0];
            $add($word['text'], \sprintf('%s, line %d', $placed['file'], $placed['line']), $word['plural'], $word['context']);
        }
        foreach ($running as $path => $content) {
            if ($this->templates === null || !str_ends_with($path, '.html.twig')) {
                continue;
            }
            foreach ($this->templates->of($path, $content) as $word) {
                $add($word['text'], \sprintf('%s, line %d', $path, $word['line']), $word['plural'], $word['context']);
            }
        }

        return array_values($words);
    }

    /**
     * @param list<array<string, mixed>>                           $declarations
     * @param \Closure(?string, string, ?string=, string=): void $add
     */
    private static function declared(array $declarations, \Closure $add): void
    {
        foreach ($declarations as $declaration) {
            $kind = \is_string($declaration['kind'] ?? null) ? $declaration['kind'] : '';
            $props = \is_array($declaration['props'] ?? null) ? $declaration['props'] : [];
            $name = \is_string($declaration['name'] ?? null) ? $declaration['name'] : '';
            $head = trim($kind . ' ' . $name);
            if ($kind === 'extension') {
                $add(self::text($props['name'] ?? null), 'extension: name');
            } elseif ($kind === 'settings') {
                self::settings($props, $head === 'settings' ? 'settings' : $head, $add);
            } elseif ($kind === 'fields') {
                $owner = \is_array($declaration['words'] ?? null) ? implode(' ', array_filter($declaration['words'], \is_string(...))) : '';
                foreach (ObjectDefinitions::declaredFields($props) as $field => [, $options]) {
                    $add(self::text($options['label'] ?? null), \sprintf('fields %s: %s, label', $owner, $field));
                    $add(self::text($options['help'] ?? null), \sprintf('fields %s: %s, help', $owner, $field));
                }
            } elseif (\in_array($kind, self::NAMED, true)) {
                foreach (['name', 'label', 'help', 'title', 'description'] as $prop) {
                    if ($prop === 'description' && $kind !== 'product_block' && $kind !== 'site_block') {
                        continue;
                    }
                    $add(self::text($props[$prop] ?? null), \sprintf('%s: %s', $head, $prop));
                }
                if ($kind === 'site_block' && \is_array($props['settings'] ?? null)) {
                    self::settings($props['settings'], $head . ': settings', $add);
                }
                if ($kind === 'checkout_field') {
                    foreach (self::labels($props['choices'] ?? null) as $label) {
                        $add($label, \sprintf('%s: choices', $head));
                    }
                }
                $nested = \is_array($declaration['nested'] ?? null) ? array_values(array_filter($declaration['nested'], \is_array(...))) : [];
                foreach ($nested as $inner) {
                    if (($inner['kind'] ?? null) === 'settings' && \is_array($inner['props'] ?? null)) {
                        self::settings($inner['props'], $head . ' settings', $add);
                    }
                }
            }
        }
    }

    /**
     * @param array<mixed, mixed>                                $props
     * @param \Closure(?string, string, ?string=, string=): void $add
     */
    private static function settings(array $props, string $head, \Closure $add): void
    {
        foreach ($props as $key => $spec) {
            if (!\is_array($spec) || !\is_string($spec['$spec'] ?? null)) {
                continue;
            }
            $add(self::text($spec['label'] ?? null), \sprintf('%s: %s, label', $head, $key));
            $add(self::text($spec['help'] ?? null), \sprintf('%s: %s, help', $head, $key));
            foreach (self::labels($spec['of'] ?? null) as $label) {
                $add($label, \sprintf('%s: %s, choices', $head, $key));
            }
        }
    }

    /**
     * The labels of a list or map of choices: a list's items, a map's values.
     *
     * @return list<string>
     */
    private static function labels(mixed $choices): array
    {
        $labels = [];
        foreach (\is_array($choices) ? $choices : [] as $label) {
            if (\is_string($label)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
