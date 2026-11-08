<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Compiled;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\SyntaxError;

/**
 * The language's door: checks a source, compiles it to its class, keeps the class in the store under the source's
 * checksum and gives back an instance ready to run with a budget and permissions. What the compiler made passes
 * the GeneratedCodeGate before it is kept, and the store checks it again before it is loaded.
 */
final readonly class Scripts
{
    public function __construct(
        private Compiler $compiler,
        private HostCatalog $catalog,
        private ScriptStore $store,
    ) {}

    /**
     * Parses and compiles; a syntax error is a finding like any other.
     *
     * @param string $scope what the class is for: the extension's slug, or `check/<id>`, `cli`; the store's site and
     *                      the source's checksum are added, so a class is one site's and one source's
     */
    public function compile(string $source, string $scope): Compiled
    {
        $suffix = preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '_' . self::checksum($source);
        try {
            $program = Parser::parse($source);
        } catch (SyntaxError $e) {
            return new Compiled($this->namespace() . '\\Script_' . $suffix, null, [new Dto\Finding($e->sourceLine, $e->sourceColumn, preg_replace('/ \(line \d+, column \d+\)$/', '', $e->getMessage()) ?? $e->getMessage())]);
        }

        $compiled = $this->compiler->compile($program, $suffix, $this->namespace());
        if ($compiled->source !== null && ($refused = GeneratedCodeGate::refuse($compiled->source, $this->store->site())) !== null) {
            // The compiler made code outside its own grammar: a bug of the compiler's, never run.
            return new Compiled($compiled->className, null, [...$compiled->findings, new Dto\Finding(1, 1, 'The compiled code was refused (' . $refused . '); the script cannot be run until the compiler is fixed')]);
        }

        return $compiled;
    }

    /**
     * The compiled class, loaded from the store or compiled and kept, as an instance with its own run context.
     *
     * @throws \InvalidArgumentException when the source does not compile; the message lists the findings
     */
    public function load(string $source, string $scope, Budget $budget = new Budget(), Grant $grant = new Grant()): CompiledScript
    {
        $key = self::key($this->store->site(), $scope, $source);
        $className = $this->namespace() . '\\Script_' . preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '_' . self::checksum($source);
        if (!class_exists($className, false) && !$this->store->load($key, $className)) {
            $compiled = $this->compile($source, $scope);
            if (!$compiled->ok() || $compiled->source === null) {
                throw new \InvalidArgumentException("The script does not compile:\n" . implode("\n", array_map(strval(...), $compiled->findings)));
            }
            $this->store->put($key, $className, $compiled->source);
        }
        $context = new Context($this->catalog, $budget, $grant);
        $script = new $className($context);
        \assert($script instanceof CompiledScript);

        return $script;
    }

    public function catalog(): HostCatalog
    {
        return $this->catalog;
    }

    /** The namespace of this site's classes: `OrillaCart\Generated\Scripts\Site<id>`. */
    public function namespace(): string
    {
        return Compiler::NAMESPACE . '\\Site' . preg_replace('/[^0-9]/', '', $this->store->site());
    }

    /** The store's key of one site's class for one source: `s<site>-<scope>-<checksum>`. */
    public static function key(string $site, string $scope, string $source): string
    {
        return 's' . preg_replace('/[^0-9]/', '', $site) . '-' . preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '-' . self::checksum($source);
    }

    /**
     * The filters and tests the language has, for the reference.
     *
     * @return array{filters: list<string>, tests: list<string>}
     */
    public static function vocabulary(): array
    {
        return ['filters' => \OrillaCart\Scripting\Domain\Ops::FILTERS, 'tests' => \OrillaCart\Scripting\Domain\Ops::TESTS];
    }

    public static function checksum(string $source): string
    {
        return substr(hash('sha256', $source), 0, 16);
    }
}
