<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Shared\Http\Routing\Tool;
use PHPUnit\Framework\TestCase;

/**
 * A tool that changes something waits for the owner's yes whatever gives it: a route, WordPress, the setup or an
 * add-on. It runs unasked only when it only reads or says why.
 */
final class ToolDefinitionTest extends TestCase
{
    public function testAToolThatChangesSomethingAndSaysNothingAsksAsAChange(): void
    {
        $tool = self::tool(readOnly: false, risk: null);

        self::assertSame(Tool::CHANGES, $tool->risk);
        self::assertTrue($tool->destructive);
        self::assertTrue(new AssistantChoices()->asksBefore($tool->risk), 'asked about by default');
    }

    public function testAToolThatSaysWhyRunsUnasked(): void
    {
        $tool = self::tool(readOnly: false, risk: null, unasked: 'a note for the shop only');

        self::assertNull($tool->risk);
        self::assertFalse($tool->destructive);
    }

    public function testAnEmptyReasonIsNoReason(): void
    {
        self::assertSame(Tool::CHANGES, self::tool(readOnly: false, risk: null, unasked: ' ')->risk);
    }

    public function testAToolThatOnlyReadsRisksNothing(): void
    {
        $tool = self::tool(readOnly: true, risk: null);

        self::assertNull($tool->risk);
        self::assertFalse($tool->destructive);
    }

    public function testANamedRiskIsKept(): void
    {
        $tool = self::tool(readOnly: false, risk: Tool::MONEY);

        self::assertSame(Tool::MONEY, $tool->risk);
        self::assertTrue($tool->destructive);
    }

    private static function tool(bool $readOnly, ?string $risk, ?string $unasked = null): ToolDefinition
    {
        return new ToolDefinition('stamp_thing', 'Stamp thing', 'Stamps a thing.', ['type' => 'object'], $readOnly, false, false, $risk, 'Things', 'manage_orillacart', true, $unasked);
    }
}
