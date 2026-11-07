<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Compiled;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\SyntaxError;

/**
 * The language's door: checks a source, compiles it to its class, keeps the class in the store under the source's
 * checksum and gives back an instance ready to run with a budget and permissions.
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
     * @param string $scope what the class is for: the site and the extension (`3/payfast`); the checksum is added
     */
    public function compile(string $source, string $scope): Compiled
    {
        $suffix = preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '_' . self::checksum($source);
        try {
            $program = Parser::parse($source);
        } catch (SyntaxError $e) {
            return new Compiled(Compiler::NAMESPACE . '\\Script_' . $suffix, null, [new Dto\Finding($e->sourceLine, $e->sourceColumn, preg_replace('/ \(line \d+, column \d+\)$/', '', $e->getMessage()) ?? $e->getMessage())]);
        }

        return $this->compiler->compile($program, $suffix);
    }

    /**
     * The compiled class, loaded from the store or compiled and kept, as an instance with its own run context.
     *
     * @param list<string> $permissions
     *
     * @throws \InvalidArgumentException when the source does not compile; the message lists the findings
     */
    public function load(string $source, string $scope, Budget $budget = new Budget(), array $permissions = [], string $extension = ''): CompiledScript
    {
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '-' . self::checksum($source);
        $className = Compiler::NAMESPACE . '\\Script_' . preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '_' . self::checksum($source);
        if (!class_exists($className, false) && !$this->store->load($key, $className)) {
            $compiled = $this->compile($source, $scope);
            if (!$compiled->ok() || $compiled->source === null) {
                throw new \InvalidArgumentException("The script does not compile:\n" . implode("\n", array_map(strval(...), $compiled->findings)));
            }
            $this->store->put($key, $className, $compiled->source);
        }
        $context = new Context($this->catalog, $budget, $permissions, $extension);
        $script = new $className($context);
        \assert($script instanceof CompiledScript);

        return $script;
    }

    public function catalog(): HostCatalog
    {
        return $this->catalog;
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
