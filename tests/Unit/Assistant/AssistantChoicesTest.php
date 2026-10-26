<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Shared\Http\Routing\Tool;
use PHPUnit\Framework\TestCase;

final class AssistantChoicesTest extends TestCase
{
    public function testEveryRiskIsAskedAboutUntilTheOwnerChooses(): void
    {
        self::assertSame(Tool::RISKS, AssistantChoices::fromArray(null)->ask);
    }

    public function testWhatTheOwnerUntickedStaysUnticked(): void
    {
        $choices = new AssistantChoices(ask: [Tool::DELETING, Tool::PUBLISHING]);

        self::assertSame([Tool::DELETING, Tool::PUBLISHING], AssistantChoices::fromArray($choices->toArray())->ask);
    }

    public function testARiskThatCameLaterIsAskedAboutOnChoicesSavedBeforeIt(): void
    {
        // Saved when there were four risks, with only deleting ticked: publishing was never theirs to untick.
        $stored = ['ask' => [Tool::DELETING], 'risks' => [Tool::MONEY, Tool::DELETING, Tool::CUSTOMERS, Tool::OPENING]];

        self::assertSame([Tool::DELETING, Tool::PUBLISHING], AssistantChoices::fromArray($stored)->ask);
    }

    public function testPublishingIsNotEmailingCustomers(): void
    {
        $choices = new AssistantChoices(ask: [Tool::PUBLISHING]);

        self::assertTrue($choices->asksBefore(Tool::PUBLISHING));
        self::assertFalse($choices->asksBefore(Tool::CUSTOMERS));
    }
}
