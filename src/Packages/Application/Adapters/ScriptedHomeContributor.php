<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Home\HomeContributor;
use OrillaCart\Extension\Home\HomeLine;
use OrillaCart\Extension\Home\HomeTile;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/** `home "id" { fn tiles(); fn needs_you() }`: home.tile() and home.line() lists on the Home screen. */
final class ScriptedHomeContributor extends ScriptedProvider implements HomeContributor
{
    public function tiles(): array
    {
        return $this->listOf('tiles', HomeTile::class);
    }

    public function needsYou(): array
    {
        return $this->listOf('needs_you', HomeLine::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function listOf(string $function, string $class): array
    {
        if (!$this->has($function)) {
            return [];
        }
        try {
            $answer = $this->call($function, []);
        } catch (HandlerFailed) {
            return [];
        }
        $items = [];
        foreach (Ops::iterable($answer === null ? [] : $answer) as $item) {
            $checked = $this->expect($item, $class, $function);
            \assert($checked instanceof $class);
            $items[] = $checked;
        }

        return $items;
    }
}
