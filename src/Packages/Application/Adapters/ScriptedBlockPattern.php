<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Storefront\BlockPattern;

/**
 * `pattern "hero" { title: "Hero", description, categories: ["banner"], file: "patterns/hero.html" }` (design 73): a
 * layout of blocks the editor offers, the package's file under patterns/ its markup. Nothing in it runs: it is
 * cleaned as an extension's template prints when the shop registers it.
 */
final class ScriptedBlockPattern extends ScriptedProvider implements BlockPattern
{
    public const string FOLDER = 'patterns/';

    public function id(): string
    {
        return $this->loaded->package->slug() . '_' . parent::id();
    }

    public function title(): string
    {
        $title = $this->declaration['props']['title'] ?? null;

        return \is_string($title) ? $this->loaded->word($title) : parent::title();
    }

    public function description(): string
    {
        $description = $this->declaration['props']['description'] ?? null;

        return \is_string($description) ? $this->loaded->word($description) : '';
    }

    public function categories(): array
    {
        $categories = $this->declaration['props']['categories'] ?? null;

        return \is_array($categories) ? array_values(array_filter($categories, static fn(mixed $category): bool => \is_string($category) && preg_match('/^[a-z][a-z0-9-]{0,39}$/', $category) === 1)) : [];
    }

    public function content(): string
    {
        $file = $this->declaration['props']['file'] ?? null;
        if (!\is_string($file) || !str_starts_with($file, self::FOLDER) || !str_ends_with($file, '.html') || str_contains($file, '..')) {
            return '';
        }

        return $this->loaded->package->runningFile($file) ?? '';
    }
}
