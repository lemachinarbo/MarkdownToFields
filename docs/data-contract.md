# Data Contract

This file only documents the technical behavior of `data()` and `dataSet()`.

Use the guide for the higher-level explanation.

## `data()`

`data()` returns the node as plain PHP arrays.

Most semantic names such as `title`, `description`, `cta`, or `image` come from your markdown structure.

The opinionated part of `data()` is the fixed structural keys it adds.

### Node Types

- Root `content()->data()`
  Returns top-level named sections only.
- Section / subsection
  Returns structural metadata, named child fields, and named child subsections.
- Field container
  Returns structural metadata, named child fields, and ordered `items`.
- Field
  Returns a plain associative array. Iterable fields expose `items`.

### Guaranteed Keys

- Root
  No metadata keys. Only named sections.
- Section / subsection
  Always `key`, `area`, `subsections`.
- Field container
  Always `key`, `area`, `items`.
- Field
  Always `type`, `key`, `area`.

These fixed keys are the main technical addition of `data()`.

### Sparse Content Keys

Keys like `html`, `text`, `markdown`, `href`, `src`, and `alt` are included only when they are meaningful for that node.

### Empty Values

- `subsections`
  Always an array on section-like nodes. Use `[]` when empty.
- `items`
  Always an array on iterable node types. Use `[]` when empty.
- Missing named child fields
  Omit the key entirely.

### Vocabulary

- `items`
  The public repeated leaf/content collection.
- `subsections`
  The public structural collection.
- `blocks`
  Internal only. Do not expose it in `data()`.

### Invariant

If a named child exists and is serializable, parent serialization must match direct child serialization:

```php
$section->data()['links'] === $section->links->data();
```

## `dataSet()`

`dataSet()` wraps `data()` in `MarkdownDataSet` / `MarkdownDataArray`.

Without a projection mode:

```php
$section->dataSet()->value() === $section->data();
```

That is the baseline contract.

### Return Types

- associative structures become `MarkdownDataSet` (`WireData`)
- list-like structures become `MarkdownDataArray` (`WireArray`)
- scalars stay scalars

### Projection Modes

`dataSet()` accepts an optional projection mode:

- `dataSet()`
  Wrap the raw `data()` shape as-is.
- `dataSet('html')`
  Project simple content nodes to their `html` value where safe.
- `dataSet('text')`
  Project simple content nodes to their `text` value where safe.

This means `dataSet()` is not html-only. It supports both frontend-friendly projections.

These are equivalent:

```php
$hero->dataSet('html');
$hero->dataSet()->html();
```

and:

```php
$hero->dataSet('text');
$hero->dataSet()->text();
```

### What `html()` / `text()` Collapse

Projection collapses only simple content-like nodes.

It does not collapse nodes that still carry real structure, such as:

- sections or subsections
- links
- images
- nodes with structural `subsections`
- non-text iterable fields

In practice that means heading/text-like fields often become strings, while images, links, and structural nodes stay object-shaped.

### Mutation Helpers

The helper layer is still experimental.

The main contract is stable:

- `data()` returns the plain structure
- `dataSet()` wraps that structure
- `dataSet('html')` and `dataSet('text')` project simple content nodes

But the exact convenience helper surface may still evolve based on real use.

Main helpers:

- `html()`
- `text()`
- `project('html' | 'text' | null)`
- `set('path.to.key', $value)`
- `set('path.to.key', fn ($current, $container, $root) => ...)`
- `merge('path.to.object', [...])`
- `map('path.to.items', fn ($item, $index, $root) => ...)`
- `setArray([...])`
- `value()`
- `toArray()`

### Dot Notation

Use dot notation for nested writes:

```php
$hero->dataSet()
  ->set('image.alt', 'Hero image')
  ->set('cta.href', '/book');
```

### `set()`

Use `set()` to replace or transform one value.

```php
$hero = $page->content()->hero
  ->dataSet('html')
  ->set('image.src', fn ($src) => $this->image($src, ['image-set' => true]));
```

### `merge()`

Use `merge()` to add or override a few keys on an object-like node.

```php
$heroImage = $this->image($content->hero->image->src ?? '', [
  'sizes' => '(min-width: 1280px) 500px, 100vw',
  'lazy' => false,
]);

$hero = $page->content()->hero
  ->dataSet()
  ->merge('image', $heroImage);
```

### `map()`

Use `map()` to transform each item of a list-like value.

```php
$topics = $page->content()->topics
  ->dataSet()
  ->map('list.items', fn ($item) => [
    'title' => $item->text ?? '',
    'href' => $item->links[0]->href ?? null,
  ]);
```

### Back To Plain Arrays

If you want the final plain structure again, call:

- `value()`
- `toArray()`

`MarkdownDataArray` also exposes `value()` and `toArray()` for ordered list values.
