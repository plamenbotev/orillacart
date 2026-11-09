<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Packages\Application\ObjectDefinitions;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * `objects.<type>` and `fields`: the extension's own records and its fields on the shop's. Every call is for the
 * running extension, checked against what it declared; a query filters on indexed fields only.
 */
final readonly class ObjectFunctions implements HostNamespace
{
    public function __construct(private ObjectStore $store, private ObjectDefinitions $definitions) {}

    public function functions(): array
    {
        return [
            new HostFunction('objects.*.create', fn(Context $c, string $type, mixed $data): array => $this->store->create($c->grant->extension, $type, ObjectDefinitions::check($this->definitions->object($c->grant->extension, $type), self::map($data), true), $this->definitions->indexed($c->grant->extension, $type)), 1, 1, 'objects.<type>.create(data): a new record of the declared kind; answers it with its id', null, HostFunction::QUERY),
            new HostFunction('objects.*.find', fn(Context $c, string $type, mixed $id): ?array => $this->store->find($c->grant->extension, $type, (int) Ops::text($id)), 1, 1, 'objects.<type>.find(id): the record, or null', null, HostFunction::QUERY),
            new HostFunction('objects.*.update', fn(Context $c, string $type, mixed $id, mixed $data): ?array => $this->store->update($c->grant->extension, $type, (int) Ops::text($id), ObjectDefinitions::check($this->definitions->object($c->grant->extension, $type), self::map($data), false), $this->definitions->indexed($c->grant->extension, $type)), 2, 2, 'objects.<type>.update(id, data): changes the fields given; answers the record, or null', null, HostFunction::QUERY),
            new HostFunction('objects.*.delete', fn(Context $c, string $type, mixed $id): bool => $this->store->delete($c->grant->extension, $type, (int) Ops::text($id)), 1, 1, 'objects.<type>.delete(id): removes the record; whether there was one', null, HostFunction::QUERY),
            new HostFunction('objects.*.query', fn(Context $c, string $type, mixed $query = []): array => $this->store->query($c->grant->extension, $type, ObjectDefinitions::query($query, $this->definitions->object($c->grant->extension, $type), $this->definitions->indexed($c->grant->extension, $type))), 0, 1, 'objects.<type>.query({ where, order_by, limit, after }): the records that match, on indexed fields; at most 200, the cursor `after` for the next page', null, HostFunction::QUERY),
            new HostFunction('objects.*.count', fn(Context $c, string $type, mixed $query = []): int => $this->store->count($c->grant->extension, $type, ObjectDefinitions::query($query, $this->definitions->object($c->grant->extension, $type), $this->definitions->indexed($c->grant->extension, $type))), 0, 1, 'objects.<type>.count({ where }): how many match', null, HostFunction::QUERY),
            new HostFunction('fields.get', fn(Context $c, mixed $kind, mixed $id, mixed $field): mixed => $this->store->fieldValue($c->grant->extension, $this->owner($c, $kind, Ops::text($field)), (int) Ops::text($id), Ops::text($field)), 3, 3, 'fields.get(kind, id, field): the extension\'s field on a product, order or customer', null, HostFunction::QUERY),
            new HostFunction('fields.of', fn(Context $c, mixed $kind, mixed $id): array => $this->store->fieldsOf($c->grant->extension, $this->owner($c, $kind, null), (int) Ops::text($id)), 2, 2, 'fields.of(kind, id): every field of the extension\'s on the record', null, HostFunction::QUERY),
            new HostFunction('fields.set', function (Context $c, mixed $kind, mixed $id, mixed $field, mixed $value): null {
                $owner = $this->owner($c, $kind, Ops::text($field));
                $declared = $this->definitions->fields($c->grant->extension, $owner);
                $checked = ObjectDefinitions::check($declared, [Ops::text($field) => $value], false);
                $this->store->setField($c->grant->extension, $owner, (int) Ops::text($id), Ops::text($field), $checked[Ops::text($field)]);

                return null;
            }, 4, 4, 'fields.set(kind, id, field, value): writes the extension\'s field on a product, order or customer; null clears it', null, HostFunction::QUERY),
        ];
    }

    /** @return array<mixed> */
    private static function map(mixed $data): array
    {
        if (!\is_array($data)) {
            throw new ScriptError('A record is a map of its fields.');
        }

        return $data;
    }

    private function owner(Context $c, mixed $kind, ?string $field): string
    {
        $owner = Ops::text($kind);
        $declared = $this->definitions->fields($c->grant->extension, $owner);
        if ($field !== null && !isset($declared[$field])) {
            throw new ScriptError(\sprintf('No field "%s" is declared on %s; declare it with "fields on %s { %s: text }".', $field, $owner, $owner, $field));
        }

        return $owner;
    }
}
