# `data()` Contract

`content()` is the rich API. `data()` is the plain-PHP serializer for templates and component-facing data.

## Node Types

- Root `content()->data()`
  Returns top-level named sections only.
- Section / subsection
  Returns structural metadata, named child fields, and named child subsections.
- Field container
  Returns structural metadata, named child fields, and ordered `items`.
- Field
  Returns a plain associative array. Iterable fields expose `items`.

## Guaranteed Keys

- Root
  No metadata keys. Only named sections.
- Section / subsection
  Always `key`, `area`, `subsections`.
- Field container
  Always `key`, `area`, `items`.
- Field
  Always `type`, `key`, `area`.

## Sparse Content Keys

Keys like `html`, `text`, `markdown`, `href`, `src`, and `alt` are included only when they are meaningful for that node.

## Empty Values

- `subsections`
  Always an array on section-like nodes. Use `[]` when empty.
- `items`
  Always an array on iterable node types. Use `[]` when empty.
- Missing named child fields
  Omit the key entirely.

## Vocabulary

- `items`
  The only public repeated leaf/content collection.
- `subsections`
  The only public structural collection.
- `blocks`
  Internal only. Do not expose it in `data()`.

## Invariant

If a named child exists and is serializable, parent serialization must match direct child serialization:

```php
$section->data()['links'] === $section->links->data();
```
