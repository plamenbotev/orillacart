<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Packages\Application\TemplateWords;
use OrillaCart\Scripting\Application\Compiler;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\Source;

/** Reads an extension's templates with the storefront's Twig: their words, and what stops them compiling. */
final readonly class TwigTemplateWords implements TemplateWords
{
    public function __construct(private Environment $twig) {}

    public function of(string $path, string $source): array
    {
        try {
            // Not marked as an extension's: the calls are read as the template wrote them.
            $tree = $this->twig->parse($this->twig->tokenize(new Source($source, $path)));
        } catch (Error) {
            return [];
        }
        $words = [];
        self::walk($tree, $words);

        return $words;
    }

    public function problem(string $extension, string $path, string $source): ?array
    {
        try {
            $this->twig->parse($this->twig->tokenize(new Source($source, $path, PackageTemplateLoader::path($extension, $path))));
        } catch (Error $e) {
            return ['line' => max(1, $e->getTemplateLine()), 'message' => $e->getRawMessage()];
        }

        return null;
    }

    public function assets(string $path, string $source): array
    {
        try {
            $tree = $this->twig->parse($this->twig->tokenize(new Source($source, $path)));
        } catch (Error) {
            return [];
        }
        $assets = [];
        self::walkAssets($tree, $assets);

        return $assets;
    }

    public function code(string $path, string $source): array
    {
        try {
            $tree = $this->twig->parse($this->twig->tokenize(new Source($source, $path)));
        } catch (Error) {
            return [];
        }
        $found = [];
        self::walkText($tree, $found);

        return $found;
    }

    /**
     * @param list<array{line: int, message: string}> $found
     */
    private static function walkText(Node $node, array &$found): void
    {
        if ($node instanceof TextNode) {
            $data = $node->getAttribute('data');
            $text = \is_string($data) ? $data : '';
            $rules = [
                '/<script\b(?![^>]*\btype\s*=\s*["\']?application\/(?:ld\+)?json\b)/i' => 'An inline script does not run in an extension\'s template: put it in a file under assets/ and print it with asset() (assets: scripts), with what it needs as json_data(…).',
                '/<[a-z][^>]*?\s(on[a-z]+)\s*=/i' => 'An "%s" attribute does not run in an extension\'s template: listen for the event in a script under assets/ (addEventListener).',
                '/\s(?:href|src|action|formaction)\s*=\s*["\']?\s*javascript:/i' => 'A javascript: address does not run in an extension\'s template: listen for the click in a script under assets/.',
            ];
            foreach ($rules as $pattern => $message) {
                if (preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($matches[0] as $index => [$match, $offset]) {
                        $found[] = ['line' => $node->getTemplateLine() + substr_count($text, "\n", 0, $offset), 'message' => \sprintf($message, $matches[1][$index][0] ?? '')];
                    }
                }
            }
        }
        foreach ($node as $child) {
            self::walkText($child, $found);
        }
    }

    /**
     * @param list<array{name: string, line: int}> $assets
     */
    private static function walkAssets(Node $node, array &$assets): void
    {
        if ($node instanceof FunctionExpression && $node->getAttribute('name') === ExtensionWordsVisitor::ASSET) {
            foreach ($node->getNode('arguments') as $arg) {
                if ($arg instanceof ConstantExpression && \is_string($arg->getAttribute('value'))) {
                    $assets[] = ['name' => $arg->getAttribute('value'), 'line' => $node->getTemplateLine()];
                }
                break;
            }
        }
        foreach ($node as $child) {
            self::walkAssets($child, $assets);
        }
    }

    /**
     * @param list<array{text: string, plural: ?string, context: string, line: int}> $words
     */
    private static function walk(Node $node, array &$words): void
    {
        if ($node instanceof FunctionExpression && \is_string($name = $node->getAttribute('name')) && isset(Compiler::WORDS[$name])) {
            $args = [];
            foreach ($node->getNode('arguments') as $arg) {
                $args[] = $arg instanceof ConstantExpression && \is_string($arg->getAttribute('value')) ? $arg->getAttribute('value') : null;
            }
            [$text, $plural, $context] = array_map(static fn(?int $index): ?string => $index === null ? null : ($args[$index] ?? null), Compiler::WORDS[$name]);
            if ($text !== null && $text !== '' && (Compiler::WORDS[$name][1] === null || $plural !== null)) {
                $words[] = ['text' => $text, 'plural' => $plural, 'context' => $context ?? '', 'line' => $node->getTemplateLine()];
            }
        }
        foreach ($node as $child) {
            self::walk($child, $words);
        }
    }
}
