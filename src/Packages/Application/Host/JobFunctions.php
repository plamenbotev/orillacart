<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\ExtensionJobHandler;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/** `jobs.push(name, payload?, at?)`: runs one of the extension's `job "name" fn(payload)` handlers in the background. */
final readonly class JobFunctions implements HostNamespace
{
    public function __construct(private Jobs $jobs, private PackageService $packages) {}

    public function functions(): array
    {
        return [
            new HostFunction('jobs.push', function (Context $c, mixed $name, mixed $payload = null, mixed $at = null): null {
                $name = Ops::text($name);
                $method = $this->method($c->grant->extension, $name) ?? throw new ScriptError(\sprintf('The extension declares no job "%s": add `job "%s" fn(payload) { … }`.', $name, $name));
                $when = null;
                if ($at !== null) {
                    try {
                        $when = new \DateTimeImmutable(Ops::text($at));
                    } catch (\Exception) {
                        throw new ScriptError(\sprintf('"%s" is not a moment.', Ops::text($at)));
                    }
                }
                $this->jobs->push(ExtensionJobHandler::TYPE, ['extension' => $c->grant->extension, 'method' => $method, 'arguments' => [Ops::plain($payload)], 'what' => 'job ' . $name], null, $when);

                return null;
            }, 1, 3, 'jobs.push(name, payload?, at?): runs the extension\'s job of that name later, in the background, with the payload; at a moment when given', writes: true),
        ];
    }

    private function method(string $extension, string $name): ?string
    {
        foreach ($this->packages->loaded() as $loaded) {
            if ($loaded->package->slug() !== $extension) {
                continue;
            }

            return self::jobMethod($loaded, $name);
        }

        return null;
    }

    public static function jobMethod(LoadedPackage $loaded, string $name): ?string
    {
        foreach ($loaded->manifest->ofKind('job') as $declaration) {
            if ($declaration['name'] === $name && $declaration['handler'] !== null) {
                return $declaration['handler'];
            }
        }

        return null;
    }
}
