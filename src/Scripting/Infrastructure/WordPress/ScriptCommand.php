<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Infrastructure\WordPress;

use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Catchable;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * `wp orillacart script check <file>`: the static check's findings. `wp orillacart script run <file> <function>
 * [--input=<json>]`: compiles the file, runs one of its top-level functions with the JSON's items as arguments,
 * and prints what it returned and what the run used. `wp orillacart script test <file> [--grant=<permissions>]`:
 * runs the file's own `test` declarations. `wp orillacart script functions`: the host's catalogue.
 */
final readonly class ScriptCommand implements CliCommand
{
    public function __construct(private Scripts $scripts, private ScriptTests $tests) {}

    public static function name(): string
    {
        return 'orillacart script';
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function check(array $args, array $options): void
    {
        $source = $this->read($args[0] ?? '');
        $compiled = $this->scripts->compile($source, 'cli');
        foreach ($compiled->findings as $finding) {
            \WP_CLI::warning((string) $finding);
        }
        if ($compiled->ok()) {
            \WP_CLI::success('The script compiles.');
        } else {
            \WP_CLI::error(\sprintf('%d finding%s.', \count($compiled->findings), \count($compiled->findings) === 1 ? '' : 's'));
        }
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function run(array $args, array $options): void
    {
        $source = $this->read($args[0] ?? '');
        $function = $args[1] ?? '';
        if ($function === '') {
            \WP_CLI::error('Name the function to run: wp orillacart script run <file> <function>');
        }
        $input = json_decode($options['input'] ?? '[]', true);
        if (!\is_array($input)) {
            \WP_CLI::error('--input takes a JSON list of arguments.');
        }
        $permissions = array_values(array_filter(explode(',', $options['grant'] ?? ''), static fn(string $permission): bool => $permission !== ''));
        try {
            $script = $this->scripts->load($source, 'cli', grant: new \OrillaCart\Scripting\Application\Dto\Grant('cli', $permissions));
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
        }
        if (!method_exists($script, 'fn_' . $function)) {
            \WP_CLI::error(\sprintf('The script has no top-level function "%s".', $function));
        }
        try {
            $result = $script->run('fn_' . $function, ...array_values($input));
            \WP_CLI::log((string) json_encode(Ops::plain($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Catchable $e) {
            \WP_CLI::warning(\sprintf('The script failed: %s', (string) json_encode($e->value(), JSON_UNESCAPED_SLASHES)));
        } catch (BudgetExceeded $e) {
            \WP_CLI::warning('The script was stopped: ' . $e->getMessage());
        }
        $used = new \ReflectionProperty($script, 'c')->getValue($script);
        if ($used instanceof \OrillaCart\Scripting\Application\Context) {
            \WP_CLI::log('Used: ' . (string) json_encode($used->used()));
        }
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function test(array $args, array $options): void
    {
        $source = $this->read($args[0] ?? '');
        $permissions = array_values(array_filter(explode(',', $options['grant'] ?? ''), static fn(string $permission): bool => $permission !== ''));
        try {
            $results = $this->tests->run($source, 'cli', new \OrillaCart\Scripting\Application\Dto\Grant('cli', $permissions));
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
        }
        if ($results === []) {
            \WP_CLI::warning('The script declares no tests: add `test "name" { } fn() { assert … }`.');

            return;
        }
        $failed = 0;
        foreach ($results as $result) {
            if ($result->ok) {
                \WP_CLI::log(\sprintf('  ok   %s', $result->name));
            } else {
                ++$failed;
                \WP_CLI::log(\sprintf('  FAIL %s: %s%s', $result->name, $result->message ?? '', $result->line === null ? '' : \sprintf(' (line %d)', $result->line)));
            }
        }
        if ($failed > 0) {
            \WP_CLI::error(\sprintf('%d of %d tests failed.', $failed, \count($results)));
        }
        \WP_CLI::success(\sprintf('%d test%s passed.', \count($results), \count($results) === 1 ? '' : 's'));
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function functions(array $args, array $options): void
    {
        foreach ($this->scripts->catalog()->all() as $function) {
            \WP_CLI::log(\sprintf('%s%s', $function->summary, $function->needs === null ? '' : \sprintf('  [needs %s]', $function->needs)));
        }
    }

    private function read(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            \WP_CLI::error('Give the path of a script file.');
        }

        return (string) file_get_contents($path);
    }
}
