<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Jobs\Application\JobRunner;
use OrillaCart\Packages\Application\Adapters\ScriptedFieldColumn;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Packages\Application\ExtensionFieldsContext;
use OrillaCart\Packages\Application\ExtensionJobHandler;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\ScriptedRegistrant;
use OrillaCart\Packages\Infrastructure\WordPress\RunExtensionSchedules;
use OrillaCart\Search\Application\DocumentBuilder;
use OrillaCart\Search\Application\Dto\DocumentOption;

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
        fields on product {
          lead_time: number { label: "Lead time", help: "Days to make it" }
          note: text { column: false }
          material: choice { of: ["wool", "cotton"], label: "Material", filter: true }
          score: number { readonly: true }
        }
        fields on order { courier_ref: text }

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

    /**
     * PLAN 332: the fields an extension declares on the shop's records are declared for the screens, shown in the
     * record's view, saved from the admin as the declaration takes them, exported and imported as columns, indexed
     * as facets when they say so, and in the templates' context.
     */
    public function testFieldsAreDeclaredShownSavedExportedIndexedAndInTemplates(): void
    {
        $declared = $this->request('GET', '/record-fields/products');
        $sections = $this->rows($this->data($declared)['sections'] ?? null);
        self::assertCount(1, $sections, (string) wp_json_encode($declared->get_data()));
        self::assertSame(['tickets', 'Tickets'], [$sections[0]['extension'], $sections[0]['name']]);
        $fields = [];
        foreach ($this->rows($sections[0]['fields']) as $field) {
            $fields[self::text($field['name'])] = $field;
        }
        self::assertSame(['lead_time', 'note', 'material', 'score'], array_keys($fields));
        self::assertSame(['number', 'Lead time', 'Days to make it', false, true], [$fields['lead_time']['kind'], $fields['lead_time']['label'], $fields['lead_time']['help'], $fields['lead_time']['readonly'], $fields['lead_time']['column']]);
        self::assertSame(['Note', false], [$fields['note']['label'], $fields['note']['column']], 'a label from the name; no column when it says so');
        self::assertSame([['wool', 'cotton'], true], [$fields['material']['of'], $fields['material']['filter']]);
        self::assertTrue($fields['score']['readonly']);
        self::assertSame(['courier_ref'], array_column($this->rows($this->rows($this->data($this->request('GET', '/record-fields/orders'))['sections'])[0]['fields']), 'name'));
        self::assertSame([], $this->data($this->request('GET', '/record-fields/customers'))['sections']);

        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => 'Scarf', 'post_status' => 'publish']);
        self::assertIsInt($id);
        try {
            $saved = $this->request('PUT', "/products/{$id}", ['price' => '30', 'type' => 'virtual'], nonce: true);
            self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));
            self::assertSame(['tickets' => ['lead_time' => null, 'note' => null, 'material' => null, 'score' => null]], $this->data($this->request('GET', "/products/{$id}"))['extensions'], 'every declared field, unset');

            $written = $this->request('PUT', "/products/{$id}/extensions/tickets", ['fields' => ['lead_time' => 3, 'material' => 'wool']], nonce: true);
            self::assertSame(200, $written->get_status(), (string) wp_json_encode($written->get_data()));
            self::assertSame(['lead_time' => 3, 'note' => null, 'material' => 'wool', 'score' => null], $this->data($written)['fields']);
            self::assertSame(422, $this->request('PUT', "/products/{$id}/extensions/tickets", ['fields' => ['material' => 'silk']], nonce: true)->get_status(), 'not one of the choices');
            self::assertSame(422, $this->request('PUT', "/products/{$id}/extensions/tickets", ['fields' => ['score' => 1]], nonce: true)->get_status(), 'readonly: the scripts write it');
            self::assertSame(422, $this->request('PUT', "/products/{$id}/extensions/tickets", ['fields' => ['colour' => 'red']], nonce: true)->get_status(), 'not declared');
            self::assertSame(422, $this->request('PUT', "/products/{$id}/extensions/other", ['fields' => []], nonce: true)->get_status(), 'no such extension');
            $before = $this->shown($id);
            self::assertSame(['lead_time' => 3, 'note' => null, 'material' => 'wool', 'score' => null], $before);
            $this->call('lead', [$id, 5]);
            $after = $this->shown($id);
            self::assertSame(5, $after['lead_time'] ?? null, 'what a script writes is what the view shows');

            // A fresh container: the registry of the one that booted collected its providers before the extension was enabled.
            $export = self::kernel()->container()->get(ProductsExport::class);
            self::assertInstanceOf(ProductsExport::class, $export);
            $export->choose(new ChooseColumns(['id', 'x_tickets_lead_time', 'x_tickets_material']));
            $columns = (string) wp_json_encode($export->columns());
            self::assertStringContainsString('"x_tickets_lead_time"', $columns);
            self::assertStringContainsString('"Lead time"', $columns);
            self::assertStringContainsString('"x_tickets_material"', $columns);
            self::assertStringNotContainsString('x_tickets_note', $columns, 'column: false');
            $stream = fopen('php://memory', 'w+b');
            self::assertIsResource($stream);
            $export->export(new CsvFile($stream, headerIds: true));
            rewind($stream);
            $lines = explode("\n", trim(substr((string) stream_get_contents($stream), 3)));
            self::assertSame('id,x_tickets_lead_time,x_tickets_material', $lines[0]);
            self::assertContains("{$id},5,wool", $lines, 'the fields as cells of the file');

            $container = self::kernel()->container();
            $packages = $container->get(PackageService::class);
            $registrant = $container->get(ScriptedRegistrant::class);
            $fields = $container->get(ExtensionFields::class);
            self::assertInstanceOf(PackageService::class, $packages);
            self::assertInstanceOf(ScriptedRegistrant::class, $registrant);
            self::assertInstanceOf(ExtensionFields::class, $fields);
            $column = null;
            foreach ($packages->loaded() as $loaded) {
                foreach ($registrant->columns($loaded) as [$owner, $candidate]) {
                    if ($owner === 'product' && $candidate->id() === 'x_tickets_lead_time') {
                        $column = $candidate;
                    }
                }
            }
            self::assertInstanceOf(ScriptedFieldColumn::class, $column);
            self::assertSame(['x_tickets_lead_time', 'Lead time'], [$column->id(), $column->label()]);
            self::assertSame('', $column->value(new ProductItem($id, 77, 'Scarf – M', null, '30')), 'the product\'s, not its variations\'');
            $column->import(new ProductItem($id, null, 'Scarf', null, '30'), '8');
            // Read through the same container: the one that answers requests holds the value as it loaded it.
            self::assertSame(8.0, $fields->of('product', $id)['tickets']['lead_time'] ?? null, 'an imported cell');
            try {
                $column->import(new ProductItem($id, null, 'Scarf', null, '30'), 'soon');
                self::fail('a cell the field does not take is refused');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('not a number', $e->getMessage());
            }

            $builder = self::kernel()->container()->get(DocumentBuilder::class);
            self::assertInstanceOf(DocumentBuilder::class, $builder);
            $document = $builder->build($id);
            self::assertNotNull($document);
            self::assertContains(['Material', 'wool'], array_map(static fn(DocumentOption $option): array => [$option->attribute, $option->name], $document->options), 'filter: true indexes the field as an attribute named by its label');
            self::assertNotContains('Lead time', array_column(array_map(static fn(DocumentOption $option): array => ['attribute' => $option->attribute], $document->options), 'attribute'));
            self::assertContains('Material', $this->data($this->request('GET', '/search-filters/attributes')), 'a search filter picks it as it picks an attribute');

            $catalog = self::kernel()->container()->get(ShopCatalog::class);
            $context = self::kernel()->container()->get(ExtensionFieldsContext::class);
            self::assertInstanceOf(ShopCatalog::class, $catalog);
            self::assertInstanceOf(ExtensionFieldsContext::class, $context);
            $offer = $catalog->offers([$id])[$id] ?? null;
            self::assertNotNull($offer);
            self::assertSame(['tickets' => ['product' => ['lead_time' => 8.0, 'note' => null, 'material' => 'wool', 'score' => null]]], $context->add('storefront/product.html.twig', ['offer' => $offer]));
            self::assertSame([], $context->add('storefront/cart.html.twig', ['lines' => []]), 'a template without one of the shop\'s records gets nothing');
        } finally {
            wp_delete_post($id, true);
        }
    }

    /**
     * The extension's fields on the product, as its view shows them.
     *
     * @return array<string, mixed>
     */
    private function shown(int $product): array
    {
        return $this->assoc($this->assoc($this->data($this->request('GET', "/products/{$product}"))['extensions'] ?? null)['tickets'] ?? null);
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
