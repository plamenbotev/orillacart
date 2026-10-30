<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Http\McpEndpointAction;
use OrillaCart\Assistant\Infrastructure\AddOnToolSource;
use OrillaCart\Assistant\Infrastructure\WordPress\ToolFiles;
use PHPUnit\Framework\TestCase;

/**
 * What the endpoint sends as JSON, an add-on tool's prefix, and a file too large to send inline.
 */
final class McpEndpointTest extends TestCase
{
    public function testOnlyWhatTheProtocolWantsAnObjectIsOneWhenEmpty(): void
    {
        $json = static fn(mixed $body): string => (string) json_encode(McpEndpointAction::objects($body));

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $json(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 'ping');
        self::assertSame('{"result":{"tools":[]}}', $json(['result' => ['tools' => []]]), 'tools/list with no tools');
        self::assertSame('{"result":{"capabilities":{"tools":{},"logging":{}}}}', $json(['result' => ['capabilities' => ['tools' => [], 'logging' => []]]]));
        self::assertSame('{"result":{"capabilities":{}}}', $json(['result' => ['capabilities' => []]]));
        self::assertSame(
            '{"result":{"tools":[{"name":"a","inputSchema":{"type":"object","properties":{}},"annotations":{}}]}}',
            $json(['result' => ['tools' => [['name' => 'a', 'inputSchema' => ['type' => 'object', 'properties' => []], 'annotations' => []]]]]),
        );
        self::assertSame('{"result":{"prompts":[{"name":"p","arguments":[]}]}}', $json(['result' => ['prompts' => [['name' => 'p', 'arguments' => []]]]]), 'a prompt\'s arguments are a list');
        self::assertSame('{"result":{"content":[],"structuredContent":[],"isError":false}}', $json(['result' => ['content' => [], 'structuredContent' => [], 'isError' => false]]), 'an empty list a tool answered');
        self::assertSame('{"result":{"structuredContent":{"orders":[],"properties":[]}}}', $json(['result' => ['structuredContent' => ['orders' => [], 'properties' => []]]]), 'what a tool answered is its own');
    }

    public function testPostMaxSizeIsReadAsPhpWritesIt(): void
    {
        self::assertSame(8 * 1024 * 1024, McpEndpointAction::bytes('8M'));
        self::assertSame(2 * 1024 ** 3, McpEndpointAction::bytes('2G'));
        self::assertSame(512 * 1024, McpEndpointAction::bytes('512k'));
        self::assertSame(1000, McpEndpointAction::bytes('1000'));
    }

    public function testAnAddOnsToolsStartWithItsPrefix(): void
    {
        self::assertSame(['parcelco_', 'parcel_co_'], AddOnToolSource::prefixes('Parcel Co'));
        self::assertSame(['e2e_'], AddOnToolSource::prefixes('E2E'));
        self::assertSame([], AddOnToolSource::prefixes(' — '));
        self::assertTrue(AddOnToolSource::prefixed('parcelco_book_pickup', 'Parcel Co'));
        self::assertTrue(AddOnToolSource::prefixed('parcel_co_book_pickup', 'Parcel Co'));
        self::assertFalse(AddOnToolSource::prefixed('book_pickup', 'Parcel Co'));
        self::assertFalse(AddOnToolSource::prefixed('parcelco_', 'Parcel Co'), 'a prefix alone names nothing');
        self::assertFalse(AddOnToolSource::prefixed('refund_order', ''));
    }

    public function testBase64BeyondWhatACallCarriesIsToldToComeAsAnAddress(): void
    {
        try {
            new ToolFiles()->write(['file_name' => 'big.csv', 'file_base64' => str_repeat('A', ToolFiles::MOST_BASE64 + 4)]);
            self::fail('a base64 file over the limit was taken');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('file_url', $e->getMessage());
        }
        $file = new ToolFiles()->write(['file_name' => 'small.txt', 'file_base64' => base64_encode('hello')]);
        self::assertSame('hello', file_get_contents($file));
        unlink($file);
    }
}
