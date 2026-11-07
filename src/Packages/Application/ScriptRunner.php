<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
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
    ) {}

    /**
     * @param list<mixed> $args
     *
     * @throws HandlerFailed
     */
    public function run(LoadedPackage $loaded, string $method, array $args): mixed
    {
        $package = $loaded->package;
        $grant = new Grant($package->slug(), $package->granted(), $loaded->manifest->hosts(), $package->settings());
        try {
            $script = $this->scripts->load($package->source(), $package->slug(), new Budget(), $grant);
            $result = $script->run($method, ...$args);
        } catch (ScriptFailure $e) {
            // Saying "unavailable" or "refused" is an answer, not a failure of the extension.
            if ($e->kind === ScriptFailure::ERROR) {
                $this->packages->failed($package->slug(), $e->getMessage());
            }
            throw new HandlerFailed($e->kind, $e->getMessage(), $e->data);
        } catch (ScriptError|BudgetExceeded $e) {
            $this->packages->failed($package->slug(), $e->getMessage());
            throw new HandlerFailed(HandlerFailed::ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('The extension "{extension}" broke the host in {method}: {message}', ['extension' => $package->slug(), 'method' => $method, 'message' => $e->getMessage(), 'exception' => $e]);
            $this->packages->failed($package->slug(), $e->getMessage());
            throw new HandlerFailed(HandlerFailed::ERROR, $e->getMessage());
        }
        $this->packages->succeeded($package->slug());

        return $result;
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
}
