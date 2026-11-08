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
use Psr\Clock\ClockInterface;

/**
 * Runs a script's own `test "name" { … } fn() { … }` declarations. Each test gets a fresh instance of the class
 * whose `http.*` answer from the test's `http` map and refuse anything not in it, whose `time.*` stand at the
 * test's `now`, whose `settings.get` reads the test's `settings` over the extension's, and whose `jobs.push`
 * records instead of queuing. Everything else is the shop's host; the package layer runs each test inside a
 * transaction it rolls back, so records and field values leave no trace.
 */
final readonly class ScriptTests
{
    public function __construct(private Scripts $scripts) {}

    /**
     * @param (callable(callable(): TestResult): TestResult)|null $isolate runs one test so that what it wrote is undone
     *
     * @return list<TestResult>
     *
     * @throws \InvalidArgumentException when the source does not compile
     */
    public function run(string $source, string $scope, Grant $grant, ?callable $isolate = null): array
    {
        $script = $this->scripts->load($source, $scope, new Budget(), $grant);
        $results = [];
        foreach ($script::manifest() as $declaration) {
            if ($declaration['kind'] !== 'test' || $declaration['handler'] === null) {
                continue;
            }
            $one = fn(): TestResult => $this->one($source, $scope, $grant, $declaration);
            $results[] = $isolate === null ? $one() : $isolate($one);
        }

        return $results;
    }

    /**
     * @param array{name: ?string, props: array<string, mixed>, handler: ?string} $declaration
     */
    private function one(string $source, string $scope, Grant $grant, array $declaration): TestResult
    {
        $name = $declaration['name'] ?? 'test';
        $props = $declaration['props'];
        $http = [];
        $jobs = [];
        $fakes = [];
        $answers = [];
        foreach (\is_array($props['http'] ?? null) ? $props['http'] : [] as $key => $value) {
            $answers[(string) $key] = $value;
        }
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $fakes[] = new HostFunction('http.' . $method, static function (Context $c, mixed $url, mixed $options = []) use (&$http, $answers, $method): array {
                $answer = self::answer($answers, strtoupper($method), Ops::text($url));
                $http[] = ['method' => strtoupper($method), 'url' => Ops::text($url), 'status' => $answer['status']];

                return $answer;
            }, 1, 2, '', 'http', HostFunction::HTTP);
        }
        $fakes[] = new HostFunction('jobs.push', static function (Context $c, mixed $name, mixed $payload = null, mixed $at = null) use (&$jobs): null {
            $jobs[] = ['name' => Ops::text($name), 'payload' => Ops::plain($payload), 'at' => $at === null ? null : Ops::text($at)];

            return null;
        }, 1, 3, '');
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
        $script = $this->scripts->load($source, $scope, new Budget(), $testGrant, $this->scripts->catalog()->with($fakes));
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

        return new TestResult($name, $outcome[0], $outcome[1], $outcome[2], $http, $jobs, $context instanceof Context ? $context->used() : []);
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
