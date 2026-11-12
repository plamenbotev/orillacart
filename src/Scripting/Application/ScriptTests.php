<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Dto\TestResult;
use OrillaCart\Scripting\Application\Host\TimeFunctions;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Runs a script's own `test "name" { … } fn() { … }` declarations. Each test gets a fresh instance of the class
 * whose `http.*` answer from the test's `http` map and refuse anything not in it, whose `time.*` stand at the
 * test's `now`, whose `settings.get` reads the test's `settings` over the grant's, and whose `jobs.push`
 * records instead of queuing. A function that reaches outside the shop runs as it says it does in tests (mail
 * held, an order placed as a test order) and is listed in the result's `effects`; one that says nothing, and
 * counts as email, refuses. The stand-ins answer for what the script declares (`$declared`) though the grant may
 * not hold it, since they reach no one; a function that makes what a test needs (a customer, a product, an order)
 * answers without its permission declared, as it says it does then (HostFunction::$madeInTests); everything else is the shop's host, held to the grant. The package layer
 * runs each test inside a transaction it rolls back, so records and field values leave no trace. For a request that may only read, the
 * package layer narrows the host so that every function that writes refuses, the recording stand-ins aside.
 */
final readonly class ScriptTests
{
    /** The stand-ins that only record what a test asked for: they reach no one and change nothing. */
    public const array RECORDED = ['http.get', 'http.post', 'http.put', 'http.patch', 'http.delete', 'jobs.push'];

    public function __construct(private Scripts $scripts) {}

    /**
     * @param (callable(callable(): TestResult, string): TestResult)|null $isolate runs one test (by its name) so that what it wrote is undone
     * @param (\Closure(HostCatalog): HostCatalog)|null          $narrow   the host each test runs against, from the one with its stand-ins
     * @param list<string>                                       $declared the permissions the script declares, which its stand-ins answer for
     * @param string|null                                        $debug    the test to record for its debugger (design 71), by name
     * @param list<string>                                       $secrets  values a recording never shows: the extension's secret settings
     *
     * @return list<TestResult>
     *
     * @throws \InvalidArgumentException when the source does not compile
     */
    public function run(string $source, string $scope, Grant $grant, ?callable $isolate = null, ?\Closure $narrow = null, array $declared = [], ?string $debug = null, array $secrets = []): array
    {
        $script = $this->scripts->load($source, $scope, new Budget(), $grant);
        $results = [];
        foreach ($script::manifest() as $declaration) {
            if ($declaration['kind'] !== 'test' || $declaration['handler'] === null) {
                continue;
            }
            $recorder = $debug !== null && ($declaration['name'] ?? 'test') === $debug ? new Recorder($secrets) : null;
            $one = fn(): TestResult => $this->one($source, $scope, $grant, $declaration, $narrow, $declared, $recorder);
            $results[] = $isolate === null ? $one() : $isolate($one, $declaration['name'] ?? 'test');
        }

        return $results;
    }

    /**
     * @param array{name: ?string, props: array<string, mixed>, handler: ?string} $declaration
     * @param (\Closure(HostCatalog): HostCatalog)|null                         $narrow
     * @param list<string>                                                     $declared
     */
    private function one(string $source, string $scope, Grant $grant, array $declaration, ?\Closure $narrow, array $declared, ?Recorder $recorder = null): TestResult
    {
        $name = $declaration['name'] ?? 'test';
        $props = $declaration['props'];
        $http = [];
        $jobs = [];
        $effects = [];
        $fakes = self::doubles($this->scripts->catalog()->all(), $effects, $declared);
        $answers = [];
        foreach (\is_array($props['http'] ?? null) ? $props['http'] : [] as $key => $value) {
            $answers[(string) $key] = $value;
        }
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $fakes[] = new HostFunction('http.' . $method, static function (Context $c, mixed $url, mixed $options = []) use (&$http, $answers, $method): array {
                $answer = self::answer($answers, strtoupper($method), Ops::text($url));
                $http[] = ['method' => strtoupper($method), 'url' => Ops::text($url), 'status' => $answer['status']];

                return $answer;
            }, 1, 2, '', 'http', HostFunction::HTTP, writes: true);
        }
        $mocked = [];
        try {
            array_push($fakes, ...$this->mocks(\is_array($props['shop'] ?? null) ? $props['shop'] : [], $mocked));
        } catch (ScriptError $e) {
            return new TestResult($name, false, $e->getMessage(), null, [], [], []);
        }
        $fakes[] = new HostFunction('jobs.push', static function (Context $c, mixed $name, mixed $payload = null, mixed $at = null) use (&$jobs): null {
            $jobs[] = ['name' => Ops::text($name), 'payload' => Ops::plain($payload), 'at' => $at === null ? null : Ops::text($at)];

            return null;
        }, 1, 3, '', writes: true);
        if (isset($props['now'])) {
            try {
                $now = new \DateTimeImmutable(Ops::text($props['now']));
            } catch (\Exception) {
                return new TestResult($name, false, \sprintf('"%s" is not a moment the test can stand at', Ops::text($props['now'])), null, [], [], []);
            }
            array_push($fakes, ...new TimeFunctions(new readonly class ($now) implements ClockInterface {
                public function __construct(private \DateTimeImmutable $now) {}

                public function now(): \DateTimeImmutable
                {
                    return $this->now;
                }
            })->functions());
        }
        $settings = $grant->settings;
        foreach (\is_array($props['settings'] ?? null) ? $props['settings'] : [] as $key => $value) {
            if (\is_string($value) || \is_bool($value)) {
                $settings[(string) $key] = $value;
            } elseif ($value !== null) {
                $settings[(string) $key] = Ops::text($value);
            }
        }
        $testGrant = new Grant($grant->extension, $grant->permissions, $grant->hosts, $settings);
        $fakes = array_map(static fn(HostFunction $f): HostFunction => $f->needs !== null && \in_array($f->needs, $declared, true)
            ? new HostFunction($f->name, $f->callable, $f->leastArguments, $f->mostArguments, $f->summary, null, $f->counts, $f->inTests, $f->writes, $f->madeInTests)
            : $f, $fakes);
        $host = $this->scripts->catalog()->with($fakes);
        $script = $this->scripts->load($source, $scope, new Budget(), $testGrant, $narrow === null ? $host : $narrow($host), $recorder);
        $handler = $declaration['handler'] ?? '';
        try {
            $script->run($handler);
            $outcome = [true, null, null];
        } catch (ScriptFailure $e) {
            $line = \is_array($e->data) && \is_int($e->data['line'] ?? null) ? $e->data['line'] : null;
            $outcome = [false, $e->kind === ScriptFailure::ASSERTION ? $e->getMessage() : \sprintf('Failed with %s: %s', $e->kind, $e->getMessage()), $line];
        } catch (ScriptError $e) {
            $outcome = [false, $e->getMessage(), null];
        } catch (BudgetExceeded $e) {
            $outcome = [false, 'Stopped: ' . $e->getMessage(), null];
        } catch (\Throwable $e) {
            $outcome = [false, 'The host broke: ' . $e->getMessage(), null];
        }
        $context = new \ReflectionProperty(CompiledScript::class, 'c')->getValue($script);
        $stack = $context instanceof Context && !$outcome[0] ? $context->stack() : [];
        // An error without a line of its own is where the run was when it failed.
        $line = $outcome[2] ?? ($stack[0]['line'] ?? null);
        if (!$outcome[0] && $recorder !== null) {
            $recorder->failed((string) $outcome[1], $line ?? 0, \count($stack));
        }

        return new TestResult($name, $outcome[0], $outcome[1], $line, $http, $jobs, $context instanceof Context ? $context->used() : [], $effects, $stack, $recorder, $mocked);
    }

    /**
     * The reads the test answers itself, from its `shop` map (the shop's, the extension's records, any namespace's): `"shop.customers.find": { … }` for any call,
     * `'shop.customers.find(2)': { … }` for a call of those arguments (written as JSON), the latter first. A mocked
     * function asked what the map does not answer fails the test, saying what key to add; functions the map does
     * not name are the real shop's. Only reads are mocked: what writes stays real, inside the test's rollback.
     *
     * @param array<array-key, mixed>                              $map
     * @param list<array{function: string, arguments: list<mixed>}> $mocked
     *
     * @return list<HostFunction>
     *
     * @throws ScriptError for a key that is not one of the shop's reads, or arguments not written as JSON
     */
    private function mocks(array $map, array &$mocked): array
    {
        /** @var array<string, array{any: bool, answer: mixed, calls: array<string, mixed>, function: HostFunction}> $answers */
        $answers = [];
        foreach ($map as $key => $answer) {
            $key = trim((string) $key);
            if (preg_match('/^([a-z_][a-z0-9_.]*)(?:\((.*)\))?$/s', $key, $match) !== 1) {
                throw new ScriptError(\sprintf('The test\'s shop map has "%s": a key is a function, or a function and its arguments written as JSON: \'shop.customers.find(2)\'.', $key));
            }
            $function = $this->scripts->catalog()->resolve($match[1])[0] ?? null;
            if ($function === null || !self::mockable($function)) {
                throw new ScriptError(\sprintf('The test\'s shop map names %s, which is not a read the map can answer: it answers functions that read the shop or the extension\'s records (shop.*, objects.<type>.find, fields.get, …); what writes stays real, and http, now and settings have maps of their own.', $match[1]));
            }
            $named = $match[1];
            $answers[$named] ??= ['any' => false, 'answer' => null, 'calls' => [], 'function' => $function];
            if (!isset($match[2])) {
                $answers[$named]['any'] = true;
                $answers[$named]['answer'] = $answer;
                continue;
            }
            $arguments = json_decode('[' . $match[2] . ']', true);
            if (!\is_array($arguments)) {
                throw new ScriptError(\sprintf('The test\'s shop map has "%s": its arguments are written as JSON, as in \'shop.products.search({"sku": "MUG-1"})\'.', $key));
            }
            $answers[$named]['calls'][self::arguments($arguments)] = $answer;
        }
        $mocks = [];
        foreach ($answers as $name => $known) {
            $function = $known['function'];
            $mocks[] = new HostFunction($name, static function (Context $c, mixed ...$arguments) use ($name, $known, &$mocked): mixed {
                $plain = array_map(Ops::plain(...), array_values($arguments));
                $mocked[] = ['function' => $name, 'arguments' => $plain];
                $written = self::arguments($plain);
                if (\array_key_exists($written, $known['calls'])) {
                    return self::revived($known['calls'][$written]);
                }
                if ($known['any']) {
                    return self::revived($known['answer']);
                }
                $call = $name . '(' . substr($written, 1, -1) . ')';

                throw new ScriptError(\sprintf('The test\'s shop map has no answer for %s: add \'%s\': … to it, or "%s": … for any call', $call, $call, $name));
            }, $function->leastArguments, $function->mostArguments, $function->summary, $function->needs, $function->counts, writes: false);
        }

        return $mocks;
    }

    /**
     * A function a test's map may answer: one that reads, past the script itself (the shop, the extension's records,
     * a namespace an add-on gives); not the language's own helpers, nor what a test answers otherwise (http, time,
     * settings, jobs).
     */
    private static function mockable(HostFunction $function): bool
    {
        if ($function->writes !== false || \in_array($function->name, self::RECORDED, true)) {
            return false;
        }
        foreach (['http.', 'time.', 'settings.', 'jobs.'] as $own) {
            if (str_starts_with($function->name, $own)) {
                return false;
            }
        }

        return $function->needs !== null || $function->counts === HostFunction::QUERY;
    }

    /** @param array<mixed> $arguments */
    private static function arguments(array $arguments): string
    {
        return (string) json_encode(array_values($arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** A declared answer as the script gets the shop's: money("9.00") written in the declaration is money. */
    private static function revived(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\count($value) === 1 && \is_string($value[Compiler::MONEY] ?? null)) {
            return Money::of($value[Compiler::MONEY]);
        }

        return array_map(self::revived(...), $value);
    }

    /**
     * The stand-ins for the functions that reach outside the shop, recording each call into $effects.
     *
     * A function that makes what a test needs stands in as it says it does without its permission, asking none,
     * when the script does not declare it.
     *
     * @param array<string, HostFunction>                             $functions
     * @param list<array{function: string, arguments: list<mixed>}> $effects
     * @param list<string>                                          $declared
     *
     * @return list<HostFunction>
     */
    private static function doubles(array $functions, array &$effects, array $declared = []): array
    {
        $doubles = [];
        foreach ($functions as $function) {
            $inTests = $function->inTests;
            $needs = $function->needs;
            if ($function->madeInTests !== null && $needs !== null && !\in_array($needs, $declared, true)) {
                $inTests = $function->madeInTests;
                $needs = null;
            }
            if ($inTests === null && $function->counts !== HostFunction::EMAIL) {
                continue;
            }
            $name = $function->name;
            $doubles[] = new HostFunction($name, static function (Context $c, mixed ...$arguments) use ($inTests, $name, &$effects): mixed {
                if ($inTests === null) {
                    throw new ScriptError(\sprintf('%s does not run in tests', $name));
                }
                $effects[] = ['function' => $name, 'arguments' => array_map(Ops::plain(...), array_values($arguments))];

                return $inTests($c, ...$arguments);
            }, $function->leastArguments, $function->mostArguments, $function->summary, $needs, $function->counts, $inTests, $function->writes, $function->madeInTests);
        }

        return $doubles;
    }

    /**
     * The answer the test's map holds for a request: under "METHOD url", under "url" for any method, or under a
     * key ending in `*` that the request starts with. Absent, the request fails as an error of the test's.
     *
     * @param array<string, mixed> $answers
     *
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    private static function answer(array $answers, string $method, string $url): array
    {
        $found = $answers[$method . ' ' . $url] ?? $answers[$url] ?? null;
        if ($found === null) {
            foreach ($answers as $key => $value) {
                $pattern = str_starts_with($key, $method . ' ') ? substr($key, \strlen($method) + 1) : $key;
                if (str_ends_with($pattern, '*') && str_starts_with($url, substr($pattern, 0, -1))) {
                    $found = $value;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new ScriptError(\sprintf('The test has no answer for %s %s: add "%s %s": { status: 200, json: { … } } to its http map', $method, $url, $method, $url));
        }
        $answer = \is_array($found) ? $found : ['body' => $found];
        $json = $answer['json'] ?? null;
        $body = isset($answer['body']) ? Ops::text($answer['body']) : ($json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($json === null && $body !== '') {
            $decoded = json_decode($body, true);
            $json = \is_array($decoded) || \is_scalar($decoded) ? $decoded : null;
        }
        $headers = [];
        foreach (\is_array($answer['headers'] ?? null) ? $answer['headers'] : [] as $header => $value) {
            $headers[strtolower((string) $header)] = Ops::text($value);
        }

        return ['status' => isset($answer['status']) ? (int) Ops::text($answer['status']) : 200, 'headers' => $headers, 'body' => $body, 'json' => $json];
    }
}
