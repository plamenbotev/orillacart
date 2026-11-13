<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Extension;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use PHPUnit\Framework\TestCase;

/** API 28: a block's own kinds of settings, and a list's fields. */
final class BlockSettingTest extends TestCase
{
    public function testAListSaysWhatItsItemsHaveAndHoldsNoListOrSecret(): void
    {
        $list = new Setting('slides', 'Slides', Setting::LIST, fields: [new Setting('title', 'Title'), new Setting('picture', 'Picture', Setting::IMAGE)]);
        self::assertSame(['title', 'picture'], array_map(static fn(Setting $field): string => $field->key, $list->fields));

        foreach ([
            static fn(): Setting => new Setting('slides', 'Slides', Setting::LIST),
            static fn(): Setting => new Setting('slides', 'Slides', Setting::LIST, fields: [new Setting('inner', 'Inner', Setting::LIST, fields: [new Setting('a', 'A')])]),
            static fn(): Setting => new Setting('slides', 'Slides', Setting::LIST, fields: [new Setting('key', 'Key', Setting::SECRET)]),
            static fn(): Setting => new Setting('title', 'Title', fields: [new Setting('a', 'A')]),
        ] as $refused) {
            try {
                $refused();
                self::fail('refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAScriptDeclaresThemAndAnExtensionsScreenLeavesThemOut(): void
    {
        $declared = ['accent' => ['$spec' => 'color', 'label' => 'Accent'], 'tabs' => ['$spec' => 'list', 'fields' => ['title' => ['$spec' => 'text'], 'more' => ['$spec' => 'list', 'fields' => ['a' => ['$spec' => 'text']]]]], 'empty' => ['$spec' => 'list'], 'name' => ['$spec' => 'text']];
        $settings = PackageManifest::settingsOf($declared);
        self::assertSame([['accent', 'color'], ['tabs', 'list'], ['name', 'text']], array_map(static fn(Setting $s): array => [$s->key, $s->type], $settings), 'a list with no fields is no setting');
        self::assertSame(['title'], array_map(static fn(Setting $s): string => $s->key, $settings[1]->fields), 'a list in a list is left out');

        $manifest = new PackageManifest([['kind' => 'settings', 'words' => [], 'name' => null, 'props' => $declared, 'functions' => [], 'nested' => [], 'handler' => null]]);
        self::assertSame(['name'], array_map(static fn(Setting $s): string => $s->key, $manifest->settings()), 'a block\'s kinds are a block\'s');
    }
}
