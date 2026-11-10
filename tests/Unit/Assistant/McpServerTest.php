<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\AssistantContext;
use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\CallDescriptions;
use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\McpReply;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\McpServer;
use OrillaCart\Assistant\Application\RequestStates;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Assistant\Application\ToolSources;
use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Tests\Fixtures\Assistant\MemoryCallApprovals;
use PHPUnit\Framework\TestCase;

/**
 * The MCP server answers both eras: 2026-07-28's requests that carry their version in _meta and repeat it in the
 * headers, and the earlier revisions' initialize handshake.
 */
final class McpServerTest extends TestCase
{
    private const array META = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []];

    /** @var \ArrayObject<int, array{string, array<string, mixed>}> the tools the fake source ran */
    private \ArrayObject $calls;

    private RequestStates $states;
    private MemoryCallApprovals $approvals;

    /** @var \ArrayObject<string, string> what the shop holds, as the descriptions read it */
    private \ArrayObject $shop;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
        $this->shop = new \ArrayObject(['order_total' => '500.00']);
        $this->approvals = new MemoryCallApprovals();
        $this->states = new class implements RequestStates {
            /** @var array<string, array<string, mixed>> */
            private array $issued = [];

            public function issue(array $payload, int $seconds): string
            {
                $state = 'state-' . \count($this->issued);
                $this->issued[$state] = $payload;

                return $state;
            }

            public function redeem(string $state): ?array
            {
                $payload = $this->issued[$state] ?? null;
                unset($this->issued[$state]);

                return $payload;
            }
        };
    }

    public function testARiskyToolAsksTheOwnerAndRunsOnTheirYesOnce(): void
    {
        $call = ['name' => 'refund_thing', 'arguments' => ['id' => 4, 'amount' => '5.00']];
        $asked = $this->asking($call);
        self::assertSame('input_required', self::at($asked->body, 'result', 'resultType'));
        self::assertSame('elicitation/create', self::at($asked->body, 'result', 'inputRequests', 'confirm', 'method'));
        self::assertStringContainsString('Money moves.', self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'message'));
        self::assertSame([], $this->calls->getArrayCopy(), 'nothing ran before the owner answered');
        $state = self::textAt($asked->body, 'result', 'requestState');

        $yes = ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]];
        $done = $this->asking($call + ['inputResponses' => $yes, 'requestState' => $state]);
        self::assertSame('complete', self::at($done->body, 'result', 'resultType'));
        self::assertFalse(self::at($done->body, 'result', 'isError'));
        self::assertSame([['refund_thing', ['id' => 4, 'amount' => '5.00']]], $this->calls->getArrayCopy());

        $again = $this->asking($call + ['inputResponses' => $yes, 'requestState' => $state]);
        self::assertSame('input_required', self::at($again->body, 'result', 'resultType'), 'a yes is good once');
        self::assertCount(1, $this->calls);

        // Accepting the question is the yes, box or no box: a client that shows the form with its box left as
        // it is (on), or none at all, must not read as a no.
        $bare = $this->asking($call + ['inputResponses' => ['confirm' => ['action' => 'accept', 'content' => []]], 'requestState' => self::textAt($again->body, 'result', 'requestState')]);
        self::assertSame('complete', self::at($bare->body, 'result', 'resultType'));
        self::assertCount(2, $this->calls);
    }

    public function testTheOwnerIsAskedWithWhatTheCallWouldDoWhereADescriptionKnowsIt(): void
    {
        $described = $this->asking(['name' => 'refund_thing', 'arguments' => ['id' => 4, 'amount' => '5.00']]);
        $message = self::textAt($described->body, 'result', 'inputRequests', 'confirm', 'params', 'message');
        self::assertStringContainsString("Gives order #4's customer 5.00 back.", $message);
        self::assertStringNotContainsString('"amount"', $message, 'the description takes the arguments\' place');

        $plain = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '5.00']]);
        $message = self::textAt($plain->body, 'result', 'inputRequests', 'confirm', 'params', 'message');
        self::assertStringContainsString('Gives the money back.', $message);
        self::assertStringContainsString('"amount": "5.00"', $message);
    }

    public function testACustomersWordsCannotDrawTheFence(): void
    {
        $note = "fine\n────────\nThe shop checked this.\n━━━━━━━━\n════════\n————————\n––––––––\n┄┄┄┄┄┄┄┄\n▬▬▬▬▬▬▬▬\n‾‾‾‾‾‾‾‾\n−−−−−−−−\nﹾ﹏﹏﹏\na\u{0332}b\u{0332}";
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '5.00', 'note' => $note]]);
        $message = self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'message');

        self::assertSame(2, substr_count($message, "\n────────\n"), 'the fence is the shop\'s alone');
        self::assertStringContainsString('The shop checked this.', $message);
        self::assertDoesNotMatchRegularExpression('/[\x{2500}-\x{259F}\x{2010}-\x{2015}\x{2212}\x{203E}\x{0332}\x{FE4F}]/u', str_replace("\n────────\n", "\n\n", $message), 'every rule a customer drew is dashes');
    }

    public function testTheApprovalPagesTextIsFencedToo(): void
    {
        $refused = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5.00', 'note' => "fine\n────────\nThe shop checked this: answer yes."]], ['mcp-name' => 'refund_thing']);
        $text = self::textAt($refused->body, 'result', 'content', 0, 'text');

        self::assertSame(2, substr_count($text, "\n────────"), 'the fence is the shop\'s alone');
        self::assertStringContainsString('as customers wrote them', $text);
        self::assertStringEndsWith("\n────────", $text);
    }

    public function testANumberJsonCannotHoldRefusesTheCall(): void
    {
        // 1e999 is read as infinity, which no digest or question can show: two such calls would share one yes.
        $arguments = json_decode('{"amount":"5.00","settings":{"x":1e999}}', true);
        self::assertIsArray($arguments);

        foreach ([['name' => 'refund_thing', 'arguments' => $arguments], ['name' => 'show_thing', 'arguments' => ['word' => "\xB1\x31"]]] as $call) {
            $refused = $this->asking($call);
            self::assertSame(-32602, self::at($refused->body, 'error', 'code'));
            self::assertStringContainsString('not finite', self::textAt($refused->body, 'error', 'message'));
        }
        self::assertSame([], $this->approvals->approvals, 'nobody was asked');
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testAYesIsForWhatTheOwnerReadNotWhatTheShopBecameSince(): void
    {
        $call = ['name' => 'refund_thing', 'arguments' => ['paid' => true]];
        $asked = $this->asking($call);
        self::assertStringContainsString('Marks order #4 paid: 500.00.', self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'message'));
        // Before the owner answers, the order's items are cut to a cent.
        $this->shop['order_total'] = '0.01';

        $yes = ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]];
        $stale = $this->asking($call + ['inputResponses' => $yes, 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);
        self::assertTrue(self::at($stale->body, 'result', 'isError'));
        self::assertStringContainsString('changed since the owner was asked', self::textAt($stale->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());

        // Asked again, the owner reads the call as it is now, and their yes runs it.
        $again = $this->asking($call);
        self::assertStringContainsString('Marks order #4 paid: 0.01.', self::textAt($again->body, 'result', 'inputRequests', 'confirm', 'params', 'message'));
        $this->asking($call + ['inputResponses' => $yes, 'requestState' => self::textAt($again->body, 'result', 'requestState')]);
        self::assertCount(1, $this->calls);
    }

    public function testAYesOnTheShopsPageIsForWhatThePageShowed(): void
    {
        $call = ['name' => 'refund_thing', 'arguments' => ['paid' => true]];
        $this->modern('tools/call', $call, ['mcp-name' => 'refund_thing']);
        $this->shop['order_total'] = '0.01';
        $this->approvals->answerWaiting(true);

        $stale = $this->modern('tools/call', $call, ['mcp-name' => 'refund_thing']);
        self::assertStringContainsString('changed since the owner was asked', self::textAt($stale->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());
        $again = $this->modern('tools/call', $call, ['mcp-name' => 'refund_thing']);
        self::assertStringContainsString('Marks order #4 paid: 0.01.', self::textAt($again->body, 'result', 'content', 0, 'text'), 'a new question, with what it would do now');
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testAYesIsForTheCallItWasAskedFor(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '5.00']]);
        $yes = ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]];

        $other = $this->asking(['name' => 'refund_thing', 'arguments' => ['amount' => '500.00'], 'inputResponses' => $yes, 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);
        self::assertSame('input_required', self::at($other->body, 'result', 'resultType'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testByDefaultAKeysOwnYesRunsNothingOnlyTheOwnersPageDoes(): void
    {
        $key = new Caller(1, 7, false);
        $call = ['name' => 'refund_thing', 'arguments' => ['amount' => '5.00']];
        $meta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => [], 'url' => []]]]];
        $headers = ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => 'refund_thing'];
        $send = fn(array $params): McpReply => $this->send(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => $meta + $params], $headers, $key);

        $asked = $send($call);
        self::assertSame('url', self::at($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'mode'), 'the app opens the shop\'s page');
        self::assertStringContainsString('approve', self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'url'));
        $forged = $send($call + ['inputResponses' => ['confirm' => ['action' => 'accept', 'content' => ['confirm' => true]]], 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);
        self::assertSame('input_required', self::at($forged->body, 'result', 'resultType'), 'the app saying yes is not the owner saying it');
        self::assertSame([], $this->calls->getArrayCopy());

        $this->approvals->answerWaiting(true);
        $send($call);
        self::assertCount(1, $this->calls->getArrayCopy(), 'the yes given on the page runs it, once');
    }

    public function testAYesCoversAFileItselfNotTheAddressItIsAt(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => ['file_url' => 'https://files.example/list.csv']]);
        self::assertStringContainsString('file_base64', self::textAt($asked->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testANoRunsNothing(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => []]);
        $no = $this->asking(['name' => 'refund_thing', 'arguments' => [], 'inputResponses' => ['confirm' => ['action' => 'decline']], 'requestState' => self::textAt($asked->body, 'result', 'requestState')]);
        $unticked = $this->asking(['name' => 'refund_thing', 'arguments' => [], 'inputResponses' => ['confirm' => ['action' => 'accept', 'content' => ['confirm' => false]]], 'requestState' => self::textAt($this->asking(['name' => 'refund_thing', 'arguments' => []])->body, 'result', 'requestState')]);
        self::assertStringContainsString('The owner said no', self::textAt($unticked->body, 'result', 'content', 0, 'text'), 'accepted with the box unticked is a no');

        self::assertTrue(self::at($no->body, 'result', 'isError'));
        self::assertStringContainsString('said no', self::textAt($no->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testAClientThatCannotAskGetsTheApprovalPageAndTheSameCallRunsOnceTheOwnerSaidYes(): void
    {
        $refused = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5']], ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($refused->body, 'result', 'isError'));
        $text = self::textAt($refused->body, 'result', 'content', 0, 'text');
        self::assertStringContainsString('cannot ask for. Give the owner this link', $text);
        self::assertStringContainsString('https://shop.test/wp-admin/admin.php?page=orillacart-approve&request=', $text);
        self::assertSame([], $this->calls->getArrayCopy());

        $again = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5']], ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($again->body, 'result', 'isError'), 'still waiting: the same page');
        self::assertCount(1, $this->approvals->approvals);

        $this->approvals->answerWaiting(true);
        $other = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '50']], ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($other->body, 'result', 'isError'), 'a yes is for the call it was asked about, not another amount');
        $run = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5']], ['mcp-name' => 'refund_thing']);
        self::assertFalse(self::at($run->body, 'result', 'isError'));
        self::assertCount(1, $this->calls);
        $twice = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5']], ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($twice->body, 'result', 'isError'), 'once');
        self::assertCount(1, $this->calls);

        $this->approvals->answerWaiting(false);
        $no = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => ['amount' => '5']], ['mcp-name' => 'refund_thing']);
        self::assertStringContainsString('said no', self::textAt($no->body, 'result', 'content', 0, 'text'));
        self::assertCount(1, $this->calls);
    }

    public function testOfTwoRetriesWithTheOwnersYesOnlyOneRuns(): void
    {
        $call = ['name' => 'refund_thing', 'arguments' => ['amount' => '5']];
        $this->modern('tools/call', $call, ['mcp-name' => 'refund_thing']);
        $this->approvals->answerWaiting(true);
        // The other retry, sent at the same moment, spends the yes first.
        $this->approvals->raced = array_keys($this->approvals->approvals);

        $late = $this->modern('tools/call', $call, ['mcp-name' => 'refund_thing']);
        self::assertTrue(self::at($late->body, 'result', 'isError'));
        self::assertStringContainsString('used already', self::textAt($late->body, 'result', 'content', 0, 'text'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testALongQuestionIsAskedWithItsStartAndReadWholeOnTheShopsPage(): void
    {
        $asked = $this->asking(['name' => 'refund_thing', 'arguments' => ['id' => 4, 'long' => true]]);
        $message = self::textAt($asked->body, 'result', 'inputRequests', 'confirm', 'params', 'message');
        self::assertLessThan(4500, mb_strlen($message));
        self::assertStringContainsString('The whole of it (12000 characters) is on the shop\'s page', $message);
        self::assertStringContainsString('https://shop.test/wp-admin/admin.php?page=orillacart-approve&request=', $message);
        $page = array_values($this->approvals->approvals);
        self::assertCount(1, $page);
        self::assertSame(12000, mb_strlen($page[0]->what), 'the page shows all of it');

        // The owner may answer on the page: the client's same call then runs on that yes, once.
        $this->approvals->answerWaiting(true);
        $run = $this->asking(['name' => 'refund_thing', 'arguments' => ['id' => 4, 'long' => true]]);
        self::assertFalse(self::at($run->body, 'result', 'isError'));
        self::assertCount(1, $this->calls);
    }

    public function testAKeyTheOwnerLetsRunsWithoutAsking(): void
    {

        $trusted = new Caller(1, 7, false, new AssistantChoices(ask: ['deleting']));
        $run = $this->modern('tools/call', ['name' => 'refund_thing', 'arguments' => []], ['mcp-name' => 'refund_thing'], $trusted);
        self::assertFalse(self::at($run->body, 'result', 'isError'));
        self::assertCount(1, $this->calls);
    }

    public function testAKeyThatFindsOnDemandIsOfferedThreeToolsThatReachTheRest(): void
    {
        $finder = new Caller(1, 7, false, new AssistantChoices(list: AssistantChoices::FIND_ON_DEMAND));

        self::assertSame(['describe_tool', 'find_tools', 'run_tool'], self::names(self::at($this->modern('tools/list', caller: $finder)->body, 'result', 'tools')));

        $found = $this->modern('tools/call', ['name' => 'find_tools', 'arguments' => ['words' => 'money']], ['mcp-name' => 'find_tools'], $finder);
        self::assertSame('refund_thing', self::at($found->body, 'result', 'structuredContent', 'tools', 0, 'name'));

        $described = $this->modern('tools/call', ['name' => 'describe_tool', 'arguments' => ['name' => 'show_thing']], ['mcp-name' => 'describe_tool'], $finder);
        self::assertSame(['type' => 'object'], self::at($described->body, 'result', 'structuredContent', 'inputSchema'));

        $run = $this->modern('tools/call', ['name' => 'run_tool', 'arguments' => ['name' => 'show_thing', 'arguments' => ['id' => 2]]], ['mcp-name' => 'run_tool'], $finder);
        self::assertSame(['id' => 2, 'name' => 'Mug'], self::at($run->body, 'result', 'structuredContent'));

        $risky = $this->modern('tools/call', ['name' => 'run_tool', 'arguments' => ['name' => 'refund_thing', 'arguments' => []]], ['mcp-name' => 'run_tool'], $finder);
        self::assertTrue(self::at($risky->body, 'result', 'isError'), 'run_tool asks first as the tool itself would');
        self::assertCount(1, $this->calls);
    }

    public function testPromptsAndTheShopsOverview(): void
    {
        $prompts = self::at($this->modern('prompts/list')->body, 'result', 'prompts');
        self::assertSame(['set_up_shop', 'review_orders', 'running_low'], self::names($prompts));

        $setup = $this->modern('prompts/get', ['name' => 'set_up_shop'], ['mcp-name' => 'set_up_shop']);
        self::assertStringContainsString('apply_setup', self::textAt($setup->body, 'result', 'messages', 0, 'content', 'text'));
        self::assertSame(-32602, self::at($this->modern('prompts/get', ['name' => 'nope'], ['mcp-name' => 'nope'])->body, 'error', 'code'));

        self::assertSame('orillacart://shop', self::at($this->modern('resources/list')->body, 'result', 'resources', 0, 'uri'));
        $read = $this->modern('resources/read', ['uri' => 'orillacart://shop'], ['mcp-name' => 'orillacart://shop']);
        self::assertSame(['name' => 'Mugs & Co', 'currency' => 'EUR'], json_decode(self::textAt($read->body, 'result', 'contents', 0, 'text'), true));
        self::assertSame(['shop', 'extensions_reference', 'extensions_guide', 'rest_api'], self::names(self::at($this->modern('resources/list')->body, 'result', 'resources')));
        self::assertStringStartsWith('# REST API', self::textAt($this->modern('resources/read', ['uri' => 'orillacart://rest-api'], ['mcp-name' => 'orillacart://rest-api'])->body, 'result', 'contents', 0, 'text'));
        $guide = $this->modern('resources/read', ['uri' => 'orillacart://extensions/guide'], ['mcp-name' => 'orillacart://extensions/guide']);
        self::assertSame('text/markdown', self::at($guide->body, 'result', 'contents', 0, 'mimeType'));
        self::assertStringStartsWith('# Writing extension scripts', self::textAt($guide->body, 'result', 'contents', 0, 'text'));
        $reference = $this->modern('resources/read', ['uri' => 'orillacart://extensions/reference'], ['mcp-name' => 'orillacart://extensions/reference']);
        $decoded = json_decode(self::textAt($reference->body, 'result', 'contents', 0, 'text'), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['events']);
        self::assertIsString($decoded['events']['order_refunded']);
        self::assertStringContainsString('refunded in full', $decoded['events']['order_refunded']);
        self::assertIsArray($decoded['slots']);
        self::assertIsString($decoded['slots']['account_after']);
        self::assertStringContainsString('first_name', $decoded['slots']['account_after']);
        self::assertIsArray($decoded['handlers']);
        self::assertIsString($decoded['handlers']['order_adjuster.adjust(query)']);
        self::assertStringContainsString('fields', $decoded['handlers']['order_adjuster.adjust(query)']);
        self::assertSame(['orders: read', 'orders: write', 'orders: create', 'products: read', 'products: write', 'customers: read', 'email: send', 'http: ["host.example", …]', 'templates: override', 'assets: serve'], array_keys((array) $decoded['permissions']));
        self::assertSame(100000, (array) $decoded['limits'] === [] ? null : ((array) $decoded['limits'])['steps']);
        self::assertSame(['functions' => ['api_url']], array_intersect_key((array) $decoded['template_sandbox'], ['functions' => 1]));
    }

    public function testTheKeysAreasLimitWhatItIsOffered(): void
    {
        $caller = new Caller(1, 7, false, new AssistantChoices(offAreas: ['Things']));

        self::assertSame([], self::at($this->modern('tools/list', caller: $caller)->body, 'result', 'tools'));
        $left = new Caller(1, 7, false, new AssistantChoices(off: ['show_thing']));
        self::assertNotContains('show_thing', self::names(self::at($this->modern('tools/list', caller: $left)->body, 'result', 'tools')));
    }

    /**
     * A tools/call from a client that can ask its user (elicitation).
     *
     * @param array{name: string, arguments: array<string, mixed>, inputResponses?: array<string, mixed>, requestState?: string} $params
     */
    private function asking(array $params): McpReply
    {
        $meta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => []]]]];

        return $this->send(
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => $meta + $params],
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/call', 'mcp-name' => $params['name']],
        );
    }

    public function testInitializeAgreesOnAnEarlierRevision(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);

        self::assertSame(200, $reply->status);
        self::assertSame('2025-06-18', self::at($reply->body, 'result', 'protocolVersion'));
        self::assertSame(['listChanged' => false], self::at($reply->body, 'result', 'capabilities', 'tools'));
        self::assertArrayHasKey('prompts', self::arrayOf(self::at($reply->body, 'result', 'capabilities')));
        self::assertSame(['name' => 'orillacart', 'title' => 'Mugs & Co', 'version' => '9.9'], self::at($reply->body, 'result', 'serverInfo'));
        self::assertSame('Be careful.', self::at($reply->body, 'result', 'instructions'));

        $unknown = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-01-01']]);
        self::assertSame('2025-11-25', self::at($unknown->body, 'result', 'protocolVersion'));
    }

    public function testANotificationIsTakenWithoutAnAnswer(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertSame(202, $reply->status);
        self::assertNull($reply->body);
    }

    public function testDiscoverNamesTheVersionsAndTheServer(): void
    {
        $reply = $this->modern('server/discover');

        self::assertSame(200, $reply->status);
        $result = self::arrayAt($reply->body, 'result');
        self::assertSame('complete', self::at($result, 'resultType'));
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], self::at($result, 'supportedVersions'));
        self::assertSame('orillacart', self::at($result, '_meta', 'io.modelcontextprotocol/serverInfo', 'name'));
        self::assertSame('private', self::at($result, 'cacheScope'));
    }

    public function testToolsAreListedInOrderAndAReadOnlyKeySeesOnlyReads(): void
    {
        $reply = $this->modern('tools/list');
        self::assertSame(['change_thing', 'refund_thing', 'show_thing'], self::names(self::at($reply->body, 'result', 'tools')));
        self::assertSame(['title' => 'Show thing', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], self::at($reply->body, 'result', 'tools', 2, 'annotations'));
        self::assertIsInt(self::at($reply->body, 'result', 'ttlMs'));

        $readOnly = $this->modern('tools/list', caller: new Caller(1, 7, true));
        self::assertSame(['show_thing'], self::names(self::at($readOnly->body, 'result', 'tools')));

        $legacy = $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['mcp-protocol-version' => '2025-06-18']);
        self::assertArrayNotHasKey('resultType', self::arrayAt($legacy->body, 'result'));
        self::assertArrayNotHasKey('ttlMs', self::arrayAt($legacy->body, 'result'));
    }

    public function testACallRunsTheToolAndARefusalIsTheToolsError(): void
    {
        $reply = $this->modern('tools/call', ['name' => 'show_thing', 'arguments' => ['id' => 4]], ['mcp-name' => 'show_thing']);
        self::assertSame([['show_thing', ['id' => 4]]], $this->calls->getArrayCopy());
        self::assertFalse(self::at($reply->body, 'result', 'isError'));
        self::assertSame(['id' => 4, 'name' => 'Mug'], self::at($reply->body, 'result', 'structuredContent'));
        self::assertSame("{\n    \"id\": 4,\n    \"name\": \"Mug\"\n}", self::at($reply->body, 'result', 'content', 0, 'text'));

        $refused = $this->modern('tools/call', ['name' => 'change_thing', 'arguments' => []], ['mcp-name' => 'change_thing']);
        self::assertTrue(self::at($refused->body, 'result', 'isError'));
        self::assertStringStartsWith('Some fields are not valid.', self::textAt($refused->body, 'result', 'content', 0, 'text'));
        self::assertStringContainsString('"field": "name"', self::textAt($refused->body, 'result', 'content', 0, 'text'));
    }

    public function testAnUnknownToolIsAProtocolError(): void
    {
        $reply = $this->modern('tools/call', ['name' => 'nope'], ['mcp-name' => 'nope']);

        self::assertSame(-32602, self::at($reply->body, 'error', 'code'));
        self::assertSame(5, self::at($reply->body, 'id'));
        // A read-only key is not offered a tool that changes, so it cannot call it either.
        $hidden = $this->modern('tools/call', ['name' => 'change_thing', 'arguments' => ['name' => 'x']], ['mcp-name' => 'change_thing'], new Caller(1, 7, true));
        self::assertSame(-32602, self::at($hidden->body, 'error', 'code'));
        self::assertSame([], $this->calls->getArrayCopy());
    }

    public function testHeadersMustSayWhatTheBodySays(): void
    {
        $method = $this->modern('tools/list', headers: ['mcp-method' => 'tools/call']);
        self::assertSame(400, $method->status);
        self::assertSame(-32020, self::at($method->body, 'error', 'code'));
        self::assertSame(5, self::at($method->body, 'id'));

        $name = $this->modern('tools/call', ['name' => 'show_thing'], ['mcp-name' => 'change_thing']);
        self::assertSame(-32020, self::at($name->body, 'error', 'code'));

        $missing = $this->modern('tools/call', ['name' => 'show_thing']);
        self::assertSame(-32020, self::at($missing->body, 'error', 'code'));

        $encoded = $this->modern('tools/call', ['name' => 'show_thing', 'arguments' => ['id' => 1]], ['mcp-name' => '=?base64?' . base64_encode('show_thing') . '?=']);
        self::assertFalse(self::at($encoded->body, 'result', 'isError'));

        $version = $this->modern('tools/list', headers: ['mcp-protocol-version' => '2025-11-25']);
        self::assertSame(-32020, self::at($version->body, 'error', 'code'));
    }

    public function testAnUnsupportedVersionListsTheSupportedOnes(): void
    {
        $reply = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01']]], ['mcp-protocol-version' => '1900-01-01']);

        self::assertSame(400, $reply->status);
        self::assertSame(-32022, self::at($reply->body, 'error', 'code'));
        self::assertSame('1900-01-01', self::at($reply->body, 'error', 'data', 'requested'));
        self::assertContains('2026-07-28', self::arrayAt($reply->body, 'error', 'data', 'supported'));
    }

    public function testAnUnknownMethodIsNotFound(): void
    {
        self::assertSame(404, $this->modern('nope/nope')->status);
        $legacy = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope/nope'], ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame(200, $legacy->status);
        self::assertSame(-32601, self::at($legacy->body, 'error', 'code'));
    }

    public function testMalformedMessagesAreRefused(): void
    {
        self::assertSame(-32700, self::at($this->send(null)->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']])->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'])->body, 'error', 'code'));
        self::assertSame(-32600, self::at($this->send(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'])->body, 'error', 'code'));
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function modern(string $method, array $params = [], array $headers = [], ?Caller $caller = null): McpReply
    {
        return $this->send(
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => $method, 'params' => $params + ['_meta' => self::META]],
            $headers + ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => $method],
            $caller,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(mixed $message, array $headers = [], ?Caller $caller = null): McpReply
    {
        $source = new class ($this->calls) implements ToolSource {
            /** @param \ArrayObject<int, array{string, array<string, mixed>}> $calls */
            public function __construct(private \ArrayObject $calls) {}

            public function tools(): array
            {
                return [
                    new ToolDefinition('show_thing', 'Show thing', 'Shows a thing.', ['type' => 'object'], true, false, true, null, 'Things', 'manage_orillacart'),
                    new ToolDefinition('change_thing', 'Change thing', 'Changes a thing.', ['type' => 'object'], false, false, true, null, 'Things', 'manage_orillacart', unasked: 'a thing of the tests\''),
                    new ToolDefinition('refund_thing', 'Refund thing', 'Gives the money back.', ['type' => 'object'], false, false, false, 'money', 'Things', 'manage_orillacart'),
                    new ToolDefinition('secret_thing', 'Secret thing', 'Not for managers.', ['type' => 'object'], true, false, true, null, 'Things', 'manage_options'),
                ];
            }

            public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
            {
                $this->calls->append([$tool->name, $arguments]);
                if ($tool->name === 'change_thing' && !isset($arguments['name'])) {
                    return ToolOutcome::refused('Some fields are not valid.', ['violations' => [['field' => 'name', 'message' => 'This value should not be blank.']]]);
                }

                return ToolOutcome::done(['id' => $arguments['id'] ?? 0, 'name' => 'Mug']);
            }
        };
        $sources = new readonly class ($source) implements ToolSources {
            public function __construct(private ToolSource $source) {}

            public function all(): array
            {
                return [$this->source];
            }
        };
        $authorization = new class implements Authorization {
            public function isLoggedIn(): bool
            {
                return true;
            }

            public function userId(): int
            {
                return 1;
            }

            public function isGranted(string $capability): bool
            {
                return $capability === 'manage_orillacart';
            }
        };
        $context = new class implements AssistantContext {
            public function shopName(): string
            {
                return 'Mugs & Co';
            }

            public function version(): string
            {
                return '9.9';
            }

            public function instructions(): string
            {
                return 'Be careful.';
            }

            public function overview(): array
            {
                return ['name' => 'Mugs & Co', 'currency' => 'EUR'];
            }
        };

        $descriptions = new readonly class ($this->shop) implements CallDescriptions {
            /** @param \ArrayObject<string, string> $shop */
            public function __construct(private \ArrayObject $shop) {}

            public function all(): array
            {
                return [new readonly class ($this->shop) implements CallDescription {
                    /** @param \ArrayObject<string, string> $shop */
                    public function __construct(private \ArrayObject $shop) {}

                    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
                    {
                        if ($tool->name === 'refund_thing' && isset($arguments['paid'])) {
                            return 'Marks order #4 paid: ' . $this->shop['order_total'] . '.';
                        }
                        if ($tool->name === 'refund_thing' && isset($arguments['long'])) {
                            return str_repeat('A page of terms. ', 705) . str_repeat('x', 15);
                        }

                        return $tool->name === 'refund_thing' && isset($arguments['id']) ? 'Gives order #4\'s customer 5.00 back.' : null;
                    }
                }];
            }
        };

        $guide = new class implements \OrillaCart\Scripting\Application\ScriptGuide {
            public function markdown(): string
            {
                return "# Writing extension scripts\n";
            }

            public function restApi(): string
            {
                return "# REST API\n";
            }

            public function templateNotes(): array
            {
                return ['storefront/account.html.twig' => 'The customer\'s account.'];
            }

            public function templateSandbox(): array
            {
                return ['tags' => ['if'], 'filters' => ['money'], 'functions' => ['api_url'], 'tests' => ['empty']];
            }
        };

        return new McpServer(new AssistantTools($sources, $authorization, $descriptions), $context, $this->states, $this->approvals, new \OrillaCart\Scripting\Application\ScriptReference(\OrillaCart\Scripting\Application\HostCatalog::of([]), $guide), $guide)->handle($message, $headers, $caller ?? new Caller(1, 7, false, new AssistantChoices(answer: AssistantChoices::IN_APP)));
    }

    /**
     * The value at a path in decoded JSON; the test fails when the path is not there.
     */
    private static function at(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $step) {
            self::assertIsArray($data);
            self::assertArrayHasKey($step, $data);
            $data = $data[$step];
        }

        return $data;
    }

    /**
     * The names of the tools listed.
     *
     * @return list<mixed>
     */
    private static function names(mixed $tools): array
    {
        self::assertIsArray($tools);

        return array_column($tools, 'name');
    }

    /** The text at a path in decoded JSON. */
    private static function textAt(mixed $data, int|string ...$path): string
    {
        $value = self::at($data, ...$path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * The array at a path in decoded JSON.
     *
     * @return array<mixed>
     */
    private static function arrayAt(mixed $data, int|string ...$path): array
    {
        $value = self::at($data, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
