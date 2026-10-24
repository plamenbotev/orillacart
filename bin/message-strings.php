<?php

declare(strict_types=1);

/*
 * Prints the words the PHP code keeps in English and translates where they leave the plugin, as PHP calls for
 * wp i18n make-pot, which finds only __() and its kind:
 *
 * - the messages of rule violations, not-founds and HTTP errors (new SomeRuleViolation('…'), parent::__construct('…'));
 * - Text: new Text('…'), Text::of('…'), and the literals a match or a ternary in them chooses from;
 * - what goes through Translations::text('…'), and the refuse('…') helpers that make violations;
 * - the validation messages of the #[Assert\…] attributes on DTOs: those given, and Symfony's defaults.
 *
 * Warns on stderr of a message with several placeholders that are not numbered (%1$s), which translators cannot
 * reorder.
 *
 *   php bin/message-strings.php src > var/i18n/messages.php
 */

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Validator\Constraint;

$root = $argv[1] ?? throw new InvalidArgumentException('Usage: php bin/message-strings.php <src folder>');
require __DIR__ . '/../vendor/autoload.php';

$collector = new class extends NodeVisitorAbstract {
    /** @var array<string, string> message => where it is, for the warnings */
    public array $messages = [];
    /** @var array<string, true> constraint classes used */
    public array $constraints = [];
    public string $file = '';
    private ?string $parent = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->parent = $node->extends === null ? null : self::name($node->extends);
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $class = self::name($node->class);
            if (self::violation($class)) {
                $this->literals(self::argument($node->args, $class === HttpException::class ? 2 : 0, 'message'), $node);
            } elseif ($class === OrillaCart\Shared\Domain\Text::class) {
                $this->literals(self::argument($node->args, 0, 'template'), $node);
            }
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = strtolower($node->class->toString());
            $method = $node->name->toLowerString();
            if ($method === '__construct' && $class === 'parent' && $this->parent !== null && self::violation($this->parent)) {
                $this->literals(self::argument($node->args, 0, 'message'), $node);
            } elseif ($method === 'of' && self::name($node->class) === OrillaCart\Shared\Domain\Text::class) {
                $this->literals(self::argument($node->args, 0, 'template'), $node);
            } elseif (\in_array($method, ['refused', 'refuse'], true)) {
                $this->literals(self::argument($node->args, 0, 'message'), $node);
            }
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $node->name->toString() === 'text') {
            $this->literals(self::argument($node->args, 0, 'text'), $node);
        }
        // $refuse('…'): a closure that makes a violation from the words.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Expr\Variable && \in_array($node->name->name, ['refuse', 'refused'], true)) {
            $this->literals(self::argument($node->args, 0, 'message'), $node);
        }
        if ($node instanceof Node\Attribute) {
            $class = self::name($node->name);
            if (self::exists($class) && is_subclass_of($class, Constraint::class)) {
                $this->constraints[$class] = true;
                foreach ($node->args as $arg) {
                    if ($arg->name !== null && stripos($arg->name->toString(), 'message') !== false) {
                        $this->literals($arg->value, $node);
                    }
                }
            }
        }

        return null;
    }

    private function literals(?Node\Expr $expression, Node $at): void
    {
        if ($expression instanceof Node\Scalar\String_) {
            $this->messages[$expression->value] ??= "{$this->file}:{$at->getStartLine()}";
        } elseif ($expression instanceof Node\Expr\Ternary) {
            $this->literals($expression->if, $at);
            $this->literals($expression->else, $at);
        } elseif ($expression instanceof Node\Expr\Match_) {
            foreach ($expression->arms as $arm) {
                $this->literals($arm->body, $at);
            }
        } elseif ($expression instanceof Node\Expr\BinaryOp\Coalesce) {
            $this->literals($expression->right, $at);
        }
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private static function argument(array $args, int $position, string $name): ?Node\Expr
    {
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name?->toString() === $name) {
                return $arg->value;
            }
        }
        $arg = $args[$position] ?? null;

        return $arg instanceof Node\Arg && $arg->name === null ? $arg->value : null;
    }

    private static function name(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
    }

    private static function violation(string $class): bool
    {
        return self::exists($class) && ($class === RuleViolation::class || is_subclass_of($class, RuleViolation::class) || $class === HttpException::class);
    }

    /** Whether the class loads here: one that extends WordPress's does not, outside WordPress. */
    private static function exists(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (Error) {
            return false;
        }
    }
};

$parser = (new ParserFactory())->createForHostVersion();
$traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), $collector);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$paths = [];
foreach ($files as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
        $paths[] = $file->getPathname();
    }
}
sort($paths);
foreach ($paths as $path) {
    $collector->file = $path;
    $traverser->traverse($parser->parse((string) file_get_contents($path)) ?? []);
}

// Symfony's own messages, for the constraints the DTOs use.
foreach (array_keys($collector->constraints) as $class) {
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        $default = $property->hasDefaultValue() ? $property->getDefaultValue() : null;
        if (\is_string($default) && stripos($property->getName(), 'message') !== false && $default !== '') {
            $collector->messages[$default] ??= $class;
        }
    }
}

echo "<?php\n\n// Generated by bin/message-strings.php from the PHP code; not loaded by the plugin.\n";
$messages = $collector->messages;
ksort($messages);
foreach ($messages as $message => $where) {
    $placeholders = preg_match_all('/%(?:\d+\$)?[sdfu]/', $message, $found);
    if ($placeholders > 1 && preg_match('/%\d+\$/', $message) !== 1) {
        fwrite(STDERR, "Number the placeholders of \"{$message}\" ({$where}).\n");
    }
    if ($placeholders > 0 || str_contains($message, '{{')) {
        echo "/* translators: the placeholders stand for what the message names, in the order the English gives them */\n";
    }
    $forms = explode('|', $message);
    echo \count($forms) === 2
        ? \sprintf("_n(%s, %s, 1, 'orillacart');\n", var_export($forms[0], true), var_export($forms[1], true))
        : \sprintf("__(%s, 'orillacart');\n", var_export($message, true));
}
