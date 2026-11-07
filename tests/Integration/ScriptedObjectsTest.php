<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Packages\Application\ExtensionJobHandler;
use OrillaCart\Packages\Infrastructure\WordPress\RunExtensionSchedules;

/**
 * Design 56: an extension's own records and its fields on the shop's, queried on indexed fields; its handlers
 * run in the background after an event, when pushed as a job, and on a schedule.
 */
final class ScriptedObjectsTest extends RestTestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "tickets" { name: "Tickets", needs: { } }

        object "ticket" {
          fields: { subject: text { longest: 50, required: true }, body: text, priority: number, state: choice { of: ["open", "closed"] }, due: date, tags: list }
          index: [state, priority, due]
        }
        fields on product { lead_time: number, note: text }

        fn open(subject, priority) {
          return objects.ticket.create({ subject: subject, priority: priority, state: "open", due: "2026-12-01", tags: ["a"] })
        }
        fn close(id) { return objects.ticket.update(id, { state: "closed" }) }
        fn urgent() { return objects.ticket.query({ where: { state: "open", "priority >=": 5 }, order_by: "-priority" })|map(fn(t) { return t.subject }) }
        fn count_open() { return objects.ticket.count({ where: { state: "open" } }) }
        fn page(after) { return objects.ticket.query({ limit: 2, after: after })|map(fn(t) { return t.id }) }
        fn bad_filter() { return objects.ticket.query({ where: { body: "x" } }) }
        fn bad_field() { return objects.ticket.create({ subject: "x", colour: "red" }) }
        fn lead(product, days) {
          fields.set("product", product, "lead_time", days)
          return [fields.get("product", product, "lead_time"), fields.of("product", product)]
        }
        fn remove(id) { return objects.ticket.delete(id) }
        fn urgent_all() { return objects.ticket.query({ where: { state: "open" }, order_by: "id" }) }
        fn hourly_count() { return objects.ticket.count({ where: { state: "closed" } }) }

        job "follow_up" fn(payload) { objects.ticket.create({ subject: "Follow up " ~ payload.who, state: "open" }) }
        fn later(who) { jobs.push("follow_up", { who: who }) }
        on order_placed fn(event) { objects.ticket.create({ subject: "Order " ~ event.order, state: "open" }) }
        every "hourly" fn() { objects.ticket.create({ subject: "Hourly check", state: "closed" }) }
        OCS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAs('administrator');
        foreach ($this->rows($this->data($this->request('GET', '/extensions'))['extensions']) as $existing) {
            $this->request('DELETE', '/extensions/' . (\is_string($existing['id'] ?? null) ? $existing['id'] : ''), nonce: true);
        }
        $this->request('POST', '/extensions', ['id' => 'tickets', 'name' => 'Tickets', 'files' => ['main.ocs' => self::SCRIPT]], nonce: true);
        $enabled = $this->request('POST', '/extensions/tickets/enable', ['grant' => []], nonce: true);
        self::assertSame(200, $enabled->get_status(), (string) wp_json_encode($enabled->get_data()));
    }

    public function testObjectsAreCreatedQueriedOnIndexedFieldsAndDeleted(): void
    {
        $first = $this->record($this->call('open', ['Printer on fire', 9]));
        self::assertSame(['Printer on fire', 9, 'open', '2026-12-01', ['a'], null, 'ticket'], [$first['subject'], $first['priority'], $first['state'], $first['due'], $first['tags'], $first['body'], $first['type']]);
        self::assertIsInt($first['id']);
        $this->call('open', ['Coffee', 1]);
        $this->call('open', ['Server down', 7]);

        self::assertSame(['Printer on fire', 'Server down'], $this->call('urgent'));
        self::assertSame(3, $this->call('count_open'));
        $closed = $this->record($this->call('close', [$first['id']]));
        self::assertSame('closed', $closed['state']);
        self::assertSame(['Server down'], $this->call('urgent'));
        self::assertSame(2, $this->call('count_open'));

        $page = $this->record($this->call('page', [null]));
        self::assertCount(2, $page);
        $next = $this->record($this->call('page', [$page[1]]));
        self::assertCount(1, $next, 'the cursor is the last id seen');

        self::assertTrue($this->call('remove', [$first['id']]));
        self::assertFalse($this->call('remove', [$first['id']]));

        $refused = $this->data($this->request('POST', '/extensions/tickets/run', ['function' => 'bad_filter'], nonce: true));
        self::assertStringContainsString('"body" is not indexed', self::text($this->assoc($refused['failure'])['message']));
        $refused = $this->data($this->request('POST', '/extensions/tickets/run', ['function' => 'bad_field'], nonce: true));
        self::assertStringContainsString('No field "colour" is declared', self::text($this->assoc($refused['failure'])['message']));

        $export = $this->data($this->request('GET', '/extensions/tickets/data'));
        self::assertCount(2, (array) $export['objects']);
        $this->request('DELETE', '/extensions/tickets', nonce: true);
        self::assertSame(404, $this->request('GET', '/extensions/tickets/data')->get_status(), 'uninstalled, records and all');
    }

    public function testFieldsOnAProductAreKeptPerExtension(): void
    {
        // A product's id: the fields are kept apart from the product, under the extension's name.
        self::assertSame([3, ['lead_time' => 3]], $this->call('lead', [999, 3]));
        self::assertSame([5, ['lead_time' => 5]], $this->call('lead', [999, 5]), 'set again: one value');
    }

    public function testHandlersRunInTheBackgroundAfterAnEventWhenPushedAndOnSchedule(): void
    {
        $this->call('later', ['Ada']);
        do_action('orillacart_order_placed', 4242);
        $hourly = RunExtensionSchedules::hook();
        self::assertNotSame('', $hourly);
        do_action($hourly);
        do_action($hourly);
        self::assertSame(0, $this->call('count_open'), 'nothing ran yet: the handlers wait for the queue');

        $runner = self::kernel()->container()->get(JobRunner::class);
        \assert($runner instanceof JobRunner);
        while ($runner->work([ExtensionJobHandler::QUEUE], 100, 'test')->count() > 0);

        self::assertSame(['Follow up Ada', 'Order 4242'], array_map(static fn(array $t): string => self::text($t['subject']), $this->rows($this->call('urgent_all'))), 'the job and the event');
        self::assertSame(1, $this->call('hourly_count'), 'the schedule ran once in the hour');
    }

    /** @return array<mixed> */
    private function record(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @param list<mixed> $arguments */
    private function call(string $function, array $arguments = []): mixed
    {
        $answer = $this->data($this->request('POST', '/extensions/tickets/run', ['function' => $function, 'arguments' => $arguments], nonce: true));
        self::assertTrue($answer['ok'] ?? false, (string) wp_json_encode($answer));

        return $answer['result'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        $rows = [];
        foreach (\is_array($value) ? $value : [] as $row) {
            $rows[] = $this->assoc($row);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        $assoc = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            $assoc[(string) $key] = $item;
        }

        return $assoc;
    }
}
