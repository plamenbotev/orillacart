<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Infrastructure\WordPress;

use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Catchable;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * `wp orillacart script check <file>`: the static check's findings. `wp orillacart script run <file> <function>
 * [--input=<json>]`: compiles the file, runs one of its top-level functions with the JSON's items as arguments,
 * and prints what it returned and what the run used. `wp orillacart script functions`: the host's catalogue.
 */
final readonly class ScriptCommand implements CliCommand
{
    public function __construct(private Scripts $scripts) {}

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
