<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Domain\ExtensionRecording;
use OrillaCart\Packages\Domain\PackageNotFound;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Shared\Http\HttpException;
use Psr\Clock\ClockInterface;

/**
 * An extension's recordings for its debugger (design 71): listed, opened, removed, live captures armed and
 * stopped, and a recording summed up for an AI agent: where it ended, the values there, the path that led there
 * and what the shop answered.
 */
final readonly class PackageRecordings
{
    /** The steps before the end an agent is shown. */
    public const int LAST_STEPS = 25;

    public function __construct(
        private PackageRepository $packages,
        private Recordings $recordings,
        private Captures $captures,
        private ClockInterface $clock,
        private PackageTests $tests,
    ) {}

    /** @return array{recordings: list<array<string, mixed>>, capture: array{what: ?string, left: int, until: string}|null} */
    public function list(string $slug): array
    {
        $this->package($slug);

        return [
            'recordings' => array_map(self::summary(...), $this->recordings->recent($slug)),
            'capture' => $this->captures->status($slug, $this->clock->now()),
        ];
    }

    /** @return array<string, mixed> */
    public function show(string $slug, int $id): array
    {
        $recording = $this->recording($slug, $id);

        // The recording's own steps, not the summary's count of them.
        return $recording->data() + self::summary($recording);
    }

    public function delete(string $slug, int $id): void
    {
        $this->recordings->delete($this->recording($slug, $id));
    }

    /** @return array{what: ?string, left: int, until: string}|null */
    public function capture(string $slug, ?string $what, int $runs, int $minutes): ?array
    {
        $this->package($slug);
        $this->captures->arm($slug, $what, max(1, min(Captures::MOST_RUNS, $runs)), $this->clock->now()->modify('+' . max(1, min(Captures::MOST_MINUTES, $minutes)) . ' minutes'));

        return $this->captures->status($slug, $this->clock->now());
    }

    public function stopCapture(string $slug): void
    {
        $this->package($slug);
        $this->captures->stop($slug);
    }

    /**
     * A test debugged, or a recording opened, summed up for an AI agent.
     *
     * @param array<string, string>|null $files the files to test instead of the saved ones
     *
     * @return array<string, mixed>
     */
    public function debug(string $slug, ?string $test, ?int $recording, ?array $files = null): array
    {
        if ($test !== null) {
            foreach ($this->tests->run($slug, $files, $test) as $result) {
                $recorded = $result['recording'] ?? null;
                if ($result['name'] === $test && \is_array($recorded)) {
                    /** @var array<string, mixed> $recorded */
                    return self::forAgent($recorded);
                }
            }

            throw new HttpException(404, 'orillacart_unknown_test', \sprintf('The extension has no test "%s".', $test));
        }
        if ($recording !== null) {
            return self::forAgent(['id' => $recording] + $this->recording($slug, $recording)->data());
        }

        throw new HttpException(422, 'orillacart_debug_what', 'Say what to debug: a test by its name, or a recording by its id.');
    }

    /**
     * Where a recording ended and how it got there, small enough for a model: the outcome, the place and stack,
     * the variables at the last line, the last steps by file and line, and each call to the shop.
     *
     * @param array<string, mixed> $recording
     *
     * @return array<string, mixed>
     */
    public static function forAgent(array $recording): array
    {
        $steps = \is_array($recording['steps'] ?? null) ? array_values($recording['steps']) : [];
        $last = null;
        $path = [];
        $calls = [];
        $marked = [];
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                continue;
            }
            $kind = $step['k'] ?? null;
            if ($kind === 'line') {
                $last = $step;
                $path[] = (\is_string($step['file'] ?? null) ? $step['file'] : '') . ':' . (\is_int($step['line'] ?? null) ? $step['line'] : 0);
            } elseif ($kind === 'call') {
                $calls[] = array_intersect_key($step, array_flip(['name', 'args', 'result', 'error', 'file', 'line']));
            } elseif ($kind === 'debug') {
                $marked[] = array_intersect_key($step, array_flip(['value', 'file', 'line']));
            }
        }

        return [
            'id' => $recording['id'] ?? null,
            'what' => $recording['what'] ?? null,
            'outcome' => $recording['outcome'] ?? null,
            'message' => $recording['message'] ?? null,
            'stack' => $recording['stack'] ?? [],
            'ended_at' => $last === null ? null : ['file' => $last['file'] ?? '', 'line' => $last['line'] ?? 0],
            'variables' => $last['vars'] ?? (object) [],
            'path' => \array_slice($path, -self::LAST_STEPS),
            'steps' => \count($steps),
            'calls' => \array_slice($calls, -self::LAST_STEPS),
            'debug' => \array_slice($marked, -self::LAST_STEPS),
            'cut' => ($recording['cut'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed> */
    private static function summary(ExtensionRecording $recording): array
    {
        $steps = $recording->data()['steps'] ?? [];

        return [
            'id' => $recording->id(),
            'source' => $recording->source(),
            'what' => $recording->what(),
            'outcome' => $recording->outcome(),
            'message' => $recording->message(),
            'steps' => \is_array($steps) ? \count($steps) : 0,
            'recorded_at' => $recording->recordedAt()->format(\DATE_ATOM),
        ];
    }

    private function recording(string $slug, int $id): ExtensionRecording
    {
        $this->package($slug);

        return $this->recordings->find($slug, $id) ?? throw new HttpException(404, 'orillacart_unknown_recording', \sprintf('The extension has no recording %d: recordings are kept a day.', $id));
    }

    private function package(string $slug): void
    {
        if ($this->packages->find($slug) === null) {
            throw new PackageNotFound($slug);
        }
    }
}
