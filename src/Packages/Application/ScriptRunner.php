<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Domain\ExtensionRun;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Security\ReadOnlyRequest;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs one handler of an enabled extension for the shop: a fresh instance with the extension's grant and the
 * budget, the failures counted (ten in a row stop the extension), and what went wrong turned into one
 * HandlerFailed the adapters map to what their contract expects.
 */
final readonly class ScriptRunner
{
    public function __construct(
        private Scripts $scripts,
        private PackageService $packages,
        private LoggerInterface $logger,
        private ?RunLog $log = null,
        private ?ClockInterface $clock = null,
        private ?ReadOnlyRequest $readOnly = null,
    ) {}

    /**
     * Whether a host function changes something or reaches someone: refused on a read-only request, whatever the
     * extension says its route or tool does.
     */
    public static function writes(HostFunction $function): bool
    {
        return $function->counts === HostFunction::EMAIL
            || ($function->needs !== null && (str_ends_with($function->needs, ':write') || str_ends_with($function->needs, ':create')))
            || \in_array($function->name, ['jobs.push', 'fields.set', 'http.post', 'http.put', 'http.patch', 'http.delete'], true)
            || preg_match('/^(objects\.\*\.(create|update|delete)|shop\.[a-z_]+\.(mark|unmark|create))$/', $function->name) === 1;
    }

    /**
     * @param list<mixed> $args
     *
     * @throws HandlerFailed
     */
    public function run(LoadedPackage $loaded, string $method, array $args): mixed
    {
        $package = $loaded->package;
        $grant = new Grant($package->slug(), $package->granted(), $loaded->manifest->hosts(), $package->settings());
        $script = null;
        try {
            $script = $this->scripts->load($package->runningSource(), $package->slug(), new Budget(), $grant, $this->readOnly?->readOnly() === true ? $this->readingOnly() : null);
            $result = $script->run($method, ...$args);
        } catch (ScriptFailure $e) {
            // Saying "unavailable" or "refused" is an answer, not a failure of the extension.
            if ($e->kind === ScriptFailure::ERROR) {
                $this->packages->failed($package->slug(), $e->getMessage());
            }
            $this->record($loaded, $method, \in_array($e->kind, [ExtensionRun::REFUSED, ExtensionRun::UNAVAILABLE], true) ? $e->kind : ExtensionRun::ERROR, $e->getMessage(), $script);
            throw new HandlerFailed($e->kind, $e->getMessage(), $e->data);
        } catch (BudgetExceeded $e) {
            $this->packages->failed($package->slug(), $e->getMessage());
            $this->record($loaded, $method, ExtensionRun::STOPPED, $e->getMessage(), $script);
            throw new HandlerFailed(HandlerFailed::ERROR, $e->getMessage());
        } catch (ScriptError $e) {
            $this->packages->failed($package->slug(), $e->getMessage());
            $this->record($loaded, $method, ExtensionRun::ERROR, $e->getMessage(), $script);
            throw new HandlerFailed(HandlerFailed::ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('The extension "{extension}" broke the host in {method}: {message}', ['extension' => $package->slug(), 'method' => $method, 'message' => $e->getMessage(), 'exception' => $e]);
            $this->packages->failed($package->slug(), $e->getMessage());
            $this->record($loaded, $method, ExtensionRun::ERROR, $e->getMessage(), $script);
            throw new HandlerFailed(HandlerFailed::ERROR, $e->getMessage());
        }
        $this->packages->succeeded($package->slug());
        $this->record($loaded, $method, ExtensionRun::OK, null, $script);

        return $result;
    }

    private function record(LoadedPackage $loaded, string $method, string $outcome, ?string $message, ?\OrillaCart\Scripting\Application\CompiledScript $script): void
    {
        if ($this->log === null) {
            return;
        }
        $used = [];
        if ($script !== null) {
            $context = new \ReflectionProperty(\OrillaCart\Scripting\Application\CompiledScript::class, 'c')->getValue($script);
            $used = $context instanceof \OrillaCart\Scripting\Application\Context ? $context->used() : [];
        }
        $this->log->record($loaded->package->slug(), self::what($loaded, $method), $outcome, $message, $used, $this->clock?->now() ?? new \DateTimeImmutable());
    }

    /** What the method is in the script's own words: `shipping_method dpd: rates`, `on order_placed`, `job remind`, or the method. */
    public static function what(LoadedPackage $loaded, string $method): string
    {
        foreach ($loaded->manifest->all() as $declaration) {
            $head = trim($declaration['kind'] . ' ' . implode(' ', $declaration['words']) . ($declaration['name'] === null ? '' : ' ' . $declaration['name']));
            if ($declaration['handler'] === $method) {
                return $head;
            }
            foreach ($declaration['functions'] as $name => $candidate) {
                if ($candidate === $method) {
                    return $head . ': ' . $name;
                }
            }
        }

        return str_starts_with($method, 'fn_') ? 'fn ' . substr($method, 3) : $method;
    }

    /**
     * Whether a declaration has the function; adapters answer for what is not declared.
     *
     * @param array{functions: array<string, string>, ...} $declaration
     */
    public static function has(array $declaration, string $function): bool
    {
        return isset($declaration['functions'][$function]);
    }

    /**
     * The method of a declaration's function.
     *
     * @param array{functions: array<string, string>, ...} $declaration
     */
    public static function method(array $declaration, string $function): string
    {
        return $declaration['functions'][$function] ?? throw new HandlerFailed(HandlerFailed::ERROR, \sprintf('The extension declares no "%s" function here.', $function));
    }

    /** The host with every function that writes refusing: for a request that may only read. */
    private function readingOnly(): HostCatalog
    {
        $refusing = [];
        foreach ($this->scripts->catalog()->all() as $function) {
            if (self::writes($function)) {
                $name = $function->name;
                $refusing[] = new HostFunction($name, static fn(): never => throw new ScriptFailure(ScriptFailure::REFUSED, \sprintf('%s changes the shop, and this request may only read (a read-only key).', $name)), $function->leastArguments, $function->mostArguments, $function->summary, $function->needs, $function->counts);
            }
        }

        return $this->scripts->catalog()->with($refusing);
    }
}
